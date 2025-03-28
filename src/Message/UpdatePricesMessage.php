<?php

namespace App\Message;

class UpdatePricesMessage
{
    private \DateTimeImmutable $timestamp;

    public function __construct()
    {
        $this->timestamp = new \DateTimeImmutable();
    }

    public function getTimestamp(): \DateTimeImmutable
    {
        return $this->timestamp;
    }
}