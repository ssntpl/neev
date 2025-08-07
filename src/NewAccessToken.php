<?php

namespace Ssntpl\Neev;

use Ssntpl\Neev\Models\AccessToken;

class NewAccessToken
{
    /**
     * The access token instance.
     *
     * @var \Ssntpl\Neev\Models\AccessToken
     */
    public $accessToken;

    /**
     * The plain text version of the token.
     *
     * @var string
     */
    public $plainTextToken;

    /**
     * Create a new access token result.
     *
     * @param  \Ssntpl\Neev\Models\AccessToken  $accessToken
     * @param  string  $plainTextToken
     * @return void
     */
    public function __construct(AccessToken $accessToken, string $plainTextToken)
    {
        $this->accessToken = $accessToken;
        $this->plainTextToken = $plainTextToken;
    }

    /**
     * Get the instance as an array.
     *
     * @return array<string, string>
     */
    public function toArray()
    {
        return [
            'accessToken' => $this->accessToken,
            'plainTextToken' => $this->plainTextToken,
        ];
    }

    /**
     * Convert the object to its JSON representation.
     *
     * @param  int  $options
     * @return string
     */
    public function toJson($options = 0)
    {
        return json_encode($this->toArray(), $options);
    }
}
