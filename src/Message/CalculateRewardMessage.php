<?php

// src/Message/CalculateRewardMessage.php

namespace App\Message;

class CalculateRewardMessage
{
    private $userId;

    public function __construct(int $userId)
    {
        $this->userId = $userId;
    }

    public function getUserId(): int
    {
        return $this->userId;
    }
}