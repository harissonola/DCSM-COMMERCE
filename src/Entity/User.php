<?php

namespace App\Entity;

use App\Repository\UserRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Validator\Constraints as Assert;
use Vich\UploaderBundle\Mapping\Annotation as Vich;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: '`user`')]
#[ORM\UniqueConstraint(name: 'UNIQ_IDENTIFIER_USERNAME', fields: ['username'])]
#[UniqueEntity(fields: ['username'], message: "Ce nom d'utilisateur existe deja")]
#[UniqueEntity(fields: ['email'], message: "Cette adresse mail existe deja")]
#[Vich\Uploadable]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 180)]
    #[Assert\NotBlank()]
    #[Assert\Length(min: 3, max: 200)]
    private ?string $username = null;

    /**
     * @var list<string> The user roles
     */
    #[ORM\Column]
    private array $roles = [];

    /**
     * @var string The hashed password
     */
    #[ORM\Column]
    private ?string $password = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank()]
    #[Assert\Length(min: 3, max: 200)]
    private ?string $fname = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank()]
    #[Assert\Length(min: 3, max: 200)]
    private ?string $lname = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank()]
    #[Assert\Country()]
    private ?string $country = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank()]
    #[Assert\Email()]
    private ?string $email = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $photo = null;

    #[ORM\Column]
    private bool $isVerified = false;

    /**
     * @var Collection<int, Transactions>
     */
    #[ORM\OneToMany(targetEntity: Transactions::class, mappedBy: 'user', orphanRemoval: true)]
    private Collection $transactions;

    /**
     * @var Collection<int, Investissement>
     */
    #[ORM\OneToMany(targetEntity: Investissement::class, mappedBy: 'User')]
    private Collection $investissements;

    /**
     * @var Collection<int, Product>
     */
    #[ORM\ManyToMany(targetEntity: Product::class, inversedBy: 'users')]
    private Collection $product;

    #[ORM\Column]
    private ?bool $isMiningBotActive = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $refCode = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $qrCodePath = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $referredBy = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $referralCode = null;

    #[ORM\Column(nullable: true)]
    private ?float $balance = 0.00;

    #[ORM\Column(nullable: true)]
    private ?bool $EmailNotifications = false;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $googleId = null;

    #[ORM\Column(nullable: true)]
    private ?float $reward = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $lastMiningTime = null;

    #[Vich\UploadableField(mapping: 'user_photo', fileNameProperty: 'photo')]
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $imageFile = null;

    #[ORM\Column(nullable: true)]
    private ?int $referralCount = null;

    #[ORM\Column]
    private float $referralRewardRate = 0.4;

    #[ORM\Column(nullable: true)]
    private ?array $lastReferralRewards = null;

    public function __construct()
    {
        $this->transactions = new ArrayCollection();
        $this->investissements = new ArrayCollection();
        $this->product = new ArrayCollection();
        $this->balance = 0.00;  // Valeur par défaut dans le constructeur
        $this->EmailNotifications = false;  // Valeur par défaut dans le constructeur
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUsername(): ?string
    {
        return $this->username;
    }

    public function setUsername(string $username): static
    {
        $this->username = $username;

        return $this;
    }

    /**
     * A visual identifier that represents this user.
     *
     * @see UserInterface
     */
    public function getUserIdentifier(): string
    {
        return (string) $this->username;
    }

    /**
     * @see UserInterface
     *
     * @return list<string>
     */
    public function getRoles(): array
    {
        $roles = $this->roles;
        // guarantee every user at least has ROLE_USER
        $roles[] = 'ROLE_USER';

        return array_unique($roles);
    }

    /**
     * @param list<string> $roles
     */
    public function setRoles(array $roles): static
    {
        $this->roles = $roles;

        return $this;
    }

    /**
     * @see PasswordAuthenticatedUserInterface
     */
    public function getPassword(): ?string
    {
        return $this->password;
    }

    public function setPassword(string $password): static
    {
        $this->password = $password;

        return $this;
    }

    /**
     * @see UserInterface
     */
    public function eraseCredentials(): void
    {
        // If you store any temporary, sensitive data on the user, clear it here
        // $this->plainPassword = null;
    }

    public function getFname(): ?string
    {
        return $this->fname;
    }

    public function setFname(string $fname): static
    {
        $this->fname = $fname;

        return $this;
    }

    public function getLname(): ?string
    {
        return $this->lname;
    }

    public function setLname(string $lname): static
    {
        $this->lname = $lname;

        return $this;
    }

    public function getCountry(): ?string
    {
        return $this->country;
    }

    public function setCountry(string $country): static
    {
        $this->country = $country;

        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = $email;

        return $this;
    }

    public function getPhoto(): ?string
    {
        return $this->photo;
    }

    public function setPhoto(?string $photo): static
    {
        $this->photo = $photo;

        return $this;
    }

    public function isVerified(): bool
    {
        return $this->isVerified;
    }

    public function setVerified(bool $isVerified): static
    {
        $this->isVerified = $isVerified;

        return $this;
    }

    /**
     * @return Collection<int, Transactions>
     */
    public function getTransactions(): Collection
    {
        return $this->transactions;
    }

    public function addTransaction(Transactions $transaction): static
    {
        if (!$this->transactions->contains($transaction)) {
            $this->transactions->add($transaction);
            $transaction->setUser($this);
        }

        return $this;
    }

    public function removeTransaction(Transactions $transaction): static
    {
        if ($this->transactions->removeElement($transaction)) {
            // set the owning side to null (unless already changed)
            if ($transaction->getUser() === $this) {
                $transaction->setUser(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, Investissement>
     */
    public function getInvestissements(): Collection
    {
        return $this->investissements;
    }

    public function addInvestissement(Investissement $investissement): static
    {
        if (!$this->investissements->contains($investissement)) {
            $this->investissements->add($investissement);
            $investissement->setUser($this);
        }

        return $this;
    }

    public function removeInvestissement(Investissement $investissement): static
    {
        if ($this->investissements->removeElement($investissement)) {
            // set the owning side to null (unless already changed)
            if ($investissement->getUser() === $this) {
                $investissement->setUser(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, Product>
     */
    public function getProduct(): Collection
    {
        return $this->product;
    }

    public function addProduct(Product $product): static
    {
        if (!$this->product->contains($product)) {
            $this->product->add($product);
        }

        return $this;
    }

    public function removeProduct(Product $product): static
    {
        $this->product->removeElement($product);

        return $this;
    }

    public function isMiningBotActive(): ?bool
    {
        return $this->isMiningBotActive;
    }

    public function setMiningBotActive(bool $isMiningBotActive): static
    {
        $this->isMiningBotActive = $isMiningBotActive;

        return $this;
    }

    public function getRefCode(): ?string
    {
        return $this->refCode;
    }

    public function setRefCode(?string $refCode): static
    {
        $this->refCode = $refCode;

        return $this;
    }

    public function getQrCodePath(): ?string
    {
        return $this->qrCodePath;
    }

    public function setQrCodePath(?string $qrCodePath): static
    {
        $this->qrCodePath = $qrCodePath;

        return $this;
    }

    public function getReferredBy(): ?string
    {
        return $this->referredBy;
    }

    public function setReferredBy(?string $referredBy): static
    {
        $this->referredBy = $referredBy;

        return $this;
    }

    public function getReferralCode(): ?string
    {
        return $this->referralCode;
    }

    public function setReferralCode(?string $referralCode): static
    {
        $this->referralCode = $referralCode;

        return $this;
    }

    public function getBalance(): ?float
    {
        return $this->balance;
    }

    public function setBalance(?float $balance): static
    {
        $this->balance = $balance;

        return $this;
    }

    public function isEmailNotifications(): ?bool
    {
        return $this->EmailNotifications;
    }

    public function setEmailNotifications(?bool $EmailNotifications): static
    {
        $this->EmailNotifications = $EmailNotifications;

        return $this;
    }

    public function getGoogleId(): ?string
    {
        return $this->googleId;
    }

    public function setGoogleId(?string $googleId): static
    {
        $this->googleId = $googleId;

        return $this;
    }

    public function getReward(): ?float
    {
        return $this->reward;
    }

    public function setReward(?float $reward): static
    {
        $this->reward = $reward;

        return $this;
    }

    public function getLastMiningTime(): ?\DateTimeInterface
    {
        return $this->lastMiningTime;
    }

    public function setLastMiningTime(?\DateTimeInterface $lastMiningTime): static
    {
        $this->lastMiningTime = $lastMiningTime;

        return $this;
    }

    public function getImageFile(): ?string
    {
        return $this->imageFile;
    }

    public function setImageFile(?string $imageFile): static
    {
        $this->imageFile = $imageFile;

        return $this;
    }

    public function getReferralCount(): ?int
    {
        return $this->referralCount;
    }

    public function setReferralCount(?int $referralCount): static
    {
        $this->referralCount = $referralCount;

        return $this;
    }

    public function getReferralRewardRate(): ?float
    {
        return $this->referralRewardRate;
    }

    public function setReferralRewardRate(float $referralRewardRate): static
    {
        $this->referralRewardRate = $referralRewardRate;

        return $this;
    }

    public function getLastReferralRewards(): ?array
    {
        return $this->lastReferralRewards;
    }

    public function setLastReferralRewards(?array $lastReferralRewards): static
    {
        $this->lastReferralRewards = $lastReferralRewards;

        return $this;
    }


    /**
     * Retourne la date de la dernière récompense pour un produit donné.
     */
    public function getLastReferralRewardTimeForProduct(Product $product): ?\DateTimeInterface
    {
        $lastReferralRewards = $this->getLastReferralRewards() ?? [];
        if (isset($lastReferralRewards[$product->getId()])) {
            return new \DateTimeImmutable($lastReferralRewards[$product->getId()]);
        }
        return null;
    }

    /**
     * Met à jour la date de la dernière récompense pour un produit donné.
     */
    public function setLastReferralRewardTimeForProduct(Product $product, \DateTimeInterface $date): self
    {
        $lastReferralRewards = $this->getLastReferralRewards() ?? [];
        // Stocke la date au format ISO 8601 (par exemple)
        $lastReferralRewards[$product->getId()] = $date->format('c');
        $this->setLastReferralRewards($lastReferralRewards);

        return $this;
    }
}
