<?php

namespace App\Factory;

use App\Entity\User;
use Zenstruck\Foundry\Persistence\PersistentProxyObjectFactory;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Intl\Countries;

/**
 * @extends PersistentProxyObjectFactory<User>
 */
final class UserFactory extends PersistentProxyObjectFactory
{
    private UserPasswordHasherInterface $passwordHasher;

    public function __construct(UserPasswordHasherInterface $passwordHasher)
    {
        parent::__construct();
        $this->passwordHasher = $passwordHasher;
    }

    public static function class(): string
    {
        return User::class;
    }

    protected function defaults(): array|callable
    {
        // Génération du prénom et du nom de famille
        $fname = self::faker()->firstName();
        $lname = self::faker()->lastName();

        // Générer l'email basé sur le prénom et le nom
        $email = strtolower($fname . '.' . $lname . '@example.com');

        // Récupérer un code de pays ISO aléatoire
        $countryCode = array_rand(Countries::getNames());  // Récupère le code pays ISO aléatoire

        return [
            'country' => $countryCode,  // Utilisation du code pays ISO
            'createdAt' => new \DateTimeImmutable(),
            'fname' => $fname,
            'lname' => $lname,
            'username' => self::faker()->userName(),
            'email' => $email,  // Email généré
            'password' => 'password123', // Sera hashé après
            'photo' => self::faker()->imageUrl(150, 150),
            'roles' => ["ROLE_USER"],
            'Level' => rand(1, 3),
            'Verified' => true,  // Assure-toi de bien utiliser 'isVerified' si c'est le nom du champ
        ];
    }

    protected function initialize(): static
    {
        return $this->afterPersist(function(User $user) {
            // Hachage du mot de passe après la création
            if ($user->getPassword()) {
                $user->setPassword($this->passwordHasher->hashPassword($user, $user->getPassword()));
            }
        });
    }
}