<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Portal;

use App\Entity\StorageType;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * 2026-07: the per-month figure was saved into "Sazba roční předem (cena za
 * celý rok)" and a customer bought a year for 1 350 Kč. The edit form must
 * reject it with the error shown ON the yearly field (not a silent 422).
 */
class StorageTypeYearlyPriceValidationTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->entityManager = static::getContainer()->get('doctrine')->getManager();
    }

    public function testMonthlyFigureInTheYearlyFieldIsRejectedOnTheField(): void
    {
        $storageType = $this->storageType();
        $this->client->loginUser($this->admin(), 'main');

        $crawler = $this->client->request('GET', $this->editUrl($storageType));
        $this->assertResponseIsSuccessful();

        $form = $crawler->filter('form[name="storage_type_form"]')->form();
        $form['storage_type_form[defaultPricePerMonthLongTerm]'] = '1500';
        $form['storage_type_form[defaultPricePerYear]'] = '1350';
        $crawler = $this->client->submit($form);

        $this->assertResponseStatusCodeSame(422);
        $fieldBlock = $crawler->filter('#storage_type_form_defaultPricePerYear')->ancestors()->first();
        $this->assertStringContainsString('Roční sazba je cena za celý rok předem', $fieldBlock->text());
    }

    public function testRealYearlyPriceIsSaved(): void
    {
        $storageType = $this->storageType();
        $this->client->loginUser($this->admin(), 'main');

        $crawler = $this->client->request('GET', $this->editUrl($storageType));
        $form = $crawler->filter('form[name="storage_type_form"]')->form();
        $form['storage_type_form[defaultPricePerMonthLongTerm]'] = '1500';
        $form['storage_type_form[defaultPricePerYear]'] = '16200';
        $this->client->submit($form);

        $this->assertResponseRedirects();
    }

    private function editUrl(StorageType $storageType): string
    {
        return '/portal/places/'.$storageType->place->id->toRfc4122().'/storage-types/'.$storageType->id->toRfc4122().'/edit';
    }

    private function storageType(): StorageType
    {
        $storageType = $this->entityManager->getRepository(StorageType::class)->findOneBy(['name' => 'Maly box']);
        \assert($storageType instanceof StorageType);

        return $storageType;
    }

    private function admin(): User
    {
        $user = $this->entityManager->getRepository(User::class)->findOneBy(['email' => 'admin@example.com']);
        \assert($user instanceof User);

        return $user;
    }
}
