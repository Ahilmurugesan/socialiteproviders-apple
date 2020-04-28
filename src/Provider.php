<?php

namespace SocialiteProviders\Apple;

use Illuminate\Support\Arr;
use Illuminate\Http\Response;
use SocialiteProviders\Manager\OAuth2\AbstractProvider;
use SocialiteProviders\Manager\OAuth2\User;
use Laravel\Socialite\Two\ProviderInterface;
use Laravel\Socialite\Two\InvalidStateException;
use Firebase\JWT\JWK;
use Lcobucci\JWT\Parser;
use Lcobucci\JWT\Signer\Rsa\Sha256;

class Provider extends AbstractProvider implements ProviderInterface
{
    /**
     * Unique Provider Identifier.
     */
    const IDENTIFIER = 'APPLE';

    private const URL = 'https://appleid.apple.com';

    /**
     * {@inheritdoc}
     */
    protected $scopes = [
        'name',
        'email'
    ];

    /**
     * The separating character for the requested scopes.
     *
     * @var string
     */
    protected $scopeSeparator = ' ';

    /**
     * {@inheritdoc}
     */
    protected function getAuthUrl($state)
    {
        return $this->buildAuthUrlFromBase(self::URL.'/auth/authorize', $state);
    }

    /**
     * {@inheritdoc}
     */
    protected function getTokenUrl()
    {
        return self::URL.'/auth/token';
    }

    /**
     * {@inheritdoc}
     */
    protected function getCodeFields($state = null)
    {
        $fields = [
            'client_id' => trim(explode(',', $this->clientId)[0]),
            'redirect_uri' => $this->redirectUrl,
            'scope' => $this->formatScopes($this->getScopes(), $this->scopeSeparator),
            'response_type' => 'code',
            'response_mode' => 'form_post',
        ];

        if ($this->usesState()) {
            $fields['state'] = $state;
            $fields['nonce'] = strtotime('12:00:00') . "-" . $state;
        }

        return array_merge($fields, $this->parameters);
    }

    /**
     * {@inheritdoc}
     */
    public function getAccessTokenResponse($code)
    {
        $response = $this->getHttpClient()->post($this->getTokenUrl(), [
            'headers' => ['Authorization' => 'Basic ' . base64_encode($this->clientId . ':' . $this->clientSecret)],
            'form_params'    => $this->getTokenFields($code),
        ]);

        return json_decode($response->getBody(), true);
    }

    /**
     * {@inheritDoc}
     */
    protected function getTokenFields($code)
    {
        return array_merge(parent::getTokenFields($code), [
            'grant_type' => 'authorization_code',
        ]);
    }

    /**
     * {@inheritdoc}
     */
    protected function getUserByToken($token)
    {
        static::verify($token);
        $claims = explode('.', $token)[1];

        return json_decode(base64_decode($claims), true);
    }

    /**
     * Verify apple jwt
     *
     * @param $jwt
     *
     * @return object
     * @see https://appleid.apple.com/auth/keys
     */
    public static function verify($jwt)
    {
        $signer = new Sha256();

        $token = (new Parser())->parse((string) $jwt);

        if ($token->getClaim('iss') !== self::URL) {
            throw new InvalidStateException("Invalid Issuer", Response::HTTP_UNAUTHORIZED);
        }
        if (!in_array($token->getClaim('aud'), array_map('trim', explode(',', config('services.apple.client_id'))))) {
            throw new InvalidStateException("Invalid Client ID", Response::HTTP_UNAUTHORIZED);
        }
        if ($token->isExpired()) {
            throw new InvalidStateException("Token Expired", Response::HTTP_UNAUTHORIZED);
        }

        $data = json_decode(file_get_contents(self::URL.'/auth/keys'), true);

        $public_keys = JWK::parseKeySet($data);

        $signature_verified = false;

        foreach ($public_keys as $res) {
            $publicKey = openssl_pkey_get_details($res);
            if ($token->verify($signer, $publicKey['key'])) {
                $signature_verified = true;
            }
        }
        if (!$signature_verified) {
            throw new InvalidStateException("Invalid JWT Signature");
        }
    }

    /**
     * {@inheritDoc}
     */
    public function user()
    {
        //Temporary fix to enable stateless
        if ($this->usesState()) {
            $this->request->session()->put('state', $this->request->input('state'));
            if ($this->hasInvalidState()) {
                throw new InvalidStateException;
            }
        }

        $response = $this->getAccessTokenResponse($this->getCode());

        $user = $this->mapUserToObject($this->getUserByToken(
            $token = Arr::get($response, 'id_token')
        ));

        if ($user instanceof User) {
            $user->setAccessTokenResponseBody($response);
        }

        return $user->setToken($token)
            ->setRefreshToken(Arr::get($response, 'refresh_token'))
            ->setExpiresIn(Arr::get($response, 'expires_in'));
    }

    /**
     * {@inheritdoc}
     */
    protected function mapUserToObject(array $user)
    {
        if (request()->filled("user")) {
            $userRequest = json_decode(request("user"), true);

            if (array_key_exists("name", $userRequest)) {
                $user["name"] = $userRequest["name"];
                $fullName = trim(
                    ($user["name"]['firstName'] ?? "")
                    . " "
                    . ($user["name"]['lastName'] ?? "")
                );
            }
        }

        return (new User)
            ->setRaw($user)
            ->map([
                "id" => $user["sub"],
                "name" => $fullName ?? null,
                "email" => $user["email"] ?? null,
            ]);
    }
}
