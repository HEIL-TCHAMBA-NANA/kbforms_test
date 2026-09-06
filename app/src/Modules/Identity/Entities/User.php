<?php
namespace Modules\Identity\Entities;

class User {
    public ?int    $id;
    public string  $firstName;
    public string  $lastName;
    public string  $email;
    public string  $password;
    public string  $accountType;
    public ?string $organization;
    public ?string $industry;
    public ?string $companySize;
    public ?string $country;
    public ?string $phone;
    public ?string $website;
    public ?string $jobTitle;
    public string  $createdAt;

    public function __construct(
        ?int    $id,
        string  $firstName,
        string  $lastName,
        string  $email,
        string  $password,
        string  $accountType,
        ?string $organization,
        ?string $industry,
        ?string $companySize,
        ?string $country,
        ?string $phone,
        ?string $website,
        ?string $jobTitle,
        string  $createdAt
    ) {
        $this->id           = $id;
        $this->firstName    = $firstName;
        $this->lastName     = $lastName;
        $this->email        = $email;
        $this->password     = $password;
        $this->accountType  = $accountType;
        $this->organization = $organization;
        $this->industry     = $industry;
        $this->companySize  = $companySize;
        $this->country      = $country;
        $this->phone        = $phone;
        $this->website      = $website;
        $this->jobTitle     = $jobTitle;
        $this->createdAt    = $createdAt;
    }
}