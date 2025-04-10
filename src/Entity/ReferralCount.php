<?php

namespace App\Entity;

use App\Repository\ReferralCountRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ReferralCountRepository::class)]
class ReferralCount
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'referralCounts')]
    private ?User $user = null;

    #[ORM\Column(length: 255)]
    private ?string $referrer = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): static
    {
        $this->user = $user;

        return $this;
    }

    public function getReferrer(): ?string
    {
        return $this->referrer;
    }

    public function setReferrer(string $referrer): static
    {
        $this->referrer = $referrer;

        return $this;
    }
}
