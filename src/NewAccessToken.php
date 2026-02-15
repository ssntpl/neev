<?php

namespace Ssntpl\Neev;

use Ssntpl\Neev\Models\AccessToken;

class NewAccessToken
{
    public function __construct(
        public AccessToken $accessToken,
        public string $plainTextToken,
    ) {
    }

    public function toArray(): array
    {
        return [
            'accessToken' => $this->accessToken,
            'plainTextToken' => $this->plainTextToken,
        ];
    }

    public function toJson($options = 0): string
    {
        return json_encode($this->toArray(), $options);
    }
}
