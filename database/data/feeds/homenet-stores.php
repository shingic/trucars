<?php

/*
| The stores Trumo gets HomeNet (Cox) feeds for, one file each in the folder
| HomeNet uploads to on our server. Read by `php artisan feeds:setup-homenet`.
|
|   file   the file name HomeNet uploads (also names the store: homenet-<key>)
|   brand  franchise brand (a CDJR store is Ram; a GM store Chevrolet). Any
|          make of the same franchise counts as the dealer's own brand.
|   fees   the store's mandatory fees, read from its own listings (Nov 2026).
|          'added' => true: left out of the feed price, so Trumo adds it on
|          top to make the price all-in. false: the store says its price
|          already includes it. Finance-only fees and optional packages are
|          left out. Amounts marked "confirm" should be checked with the store.
|   name, address, city, province, postal_code, phone
|          the store as its feed gives it, tidied (the feed spells Grande
|          Prairie "Grand Prairie"; Kelowna's phone is a placeholder).
*/

return [
    ['file' => 'Trumo_HarrisCDJR.csv', 'name' => 'Harris Chrysler Dodge Jeep Ram', 'address' => '721 Meaford Avenue', 'city' => 'Victoria', 'province' => 'BC', 'postal_code' => 'V8V 3M5', 'phone' => '250-386-2411',
        'brand' => 'Ram', 'fees' => [
        ['Documentation fee', 99700, true],
    ]],
    ['file' => 'Trumo_DuncanCDJR.csv', 'name' => 'Duncan Dodge', 'address' => '461 Trans Canada Hwy', 'city' => 'Duncan', 'province' => 'BC', 'postal_code' => 'V9L 3R7', 'phone' => '250-748-8144',
        'brand' => 'Ram', 'fees' => [
        ['Documentation fee', 99700, true],
        ['Fuel surcharge', 15000, true],
    ]],
    ['file' => 'Trumo_NanaimoChrysler.csv', 'name' => 'Nanaimo Chrysler', 'address' => '4170 Wellington Rd', 'city' => 'Nanaimo', 'province' => 'BC', 'postal_code' => 'V9T 2H3', 'phone' => '250-758-1191',
        'brand' => 'Ram', 'fees' => [
        ['Documentation fee', 99900, true],
    ]],
    ['file' => 'Trumo_HarrisKIA.csv', 'name' => 'Harris Kia', 'address' => '2575 Bowen Rd', 'city' => 'Nanaimo', 'province' => 'BC', 'postal_code' => 'V9T 3L4', 'phone' => '250-751-1168',
        'brand' => 'Kia', 'fees' => [
        ['Documentation fee', 99900, true], // confirm: 13 listings say $699
    ]],
    ['file' => 'Trumo_HarrisMazda.csv', 'name' => 'Harris Mazda', 'address' => '2525 Bowen Rd', 'city' => 'Nanaimo', 'province' => 'BC', 'postal_code' => 'V9T 3L2', 'phone' => '250-758-9125',
        'brand' => 'Mazda', 'fees' => [
        ['Documentation fee', 99500, true],
    ]],
    ['file' => 'Trumo_HarrisOceanside.csv', 'name' => 'Harris Oceanside', 'address' => '512 East Island Highway', 'city' => 'Parksville', 'province' => 'BC', 'postal_code' => 'V9P 2G7', 'phone' => '250-758-5000',
        'brand' => 'Chevrolet', 'fees' => [
        ['Documentation fee', 69900, true],
        ['Certification fee', 34500, true],
    ]],
    ['file' => 'Trumo_IslandGM.csv', 'name' => 'Island Chevrolet Buick GMC', 'address' => '6300 Trans Canada Hwy', 'city' => 'North Cowichan', 'province' => 'BC', 'postal_code' => 'V9L 0C1', 'phone' => '250-746-7131',
        'brand' => 'Chevrolet', 'fees' => [
        ['Documentation fee', 69900, false],
        ['Certification fee', 39900, false],
        ['VSA fee', 1000, false],
    ]],
    ['file' => 'Trumo_KELOWNAMAZDA.csv', 'name' => 'Kelowna Mazda', 'address' => '2560 Enterprise Way', 'city' => 'Kelowna', 'province' => 'BC', 'postal_code' => 'V1X 7X5', 'phone' => null,
        'brand' => 'Mazda', 'fees' => [
        ['Documentation fee', 69900, true], // confirm: only 4 listings mention fees
    ]],
    ['file' => 'Trumo_MazdaofGrandePrairie.csv', 'name' => 'Mazda of Grande Prairie', 'address' => '12709 100 St.', 'city' => 'Grande Prairie', 'province' => 'AB', 'postal_code' => 'T8V 4H2', 'phone' => '780-532-5005',
        'brand' => 'Mazda', 'fees' => [
        ['Documentation fee', 79500, false], // confirm: listings say the price "includes" it
        ['AMVIC fee', 1000, false],
    ]],
    ['file' => 'Trumo_PentictonHonda.csv', 'name' => 'Penticton Honda', 'address' => '510 Duncan Ave West', 'city' => 'Penticton', 'province' => 'BC', 'postal_code' => 'V2A 7N1', 'phone' => '888-543-3115',
        'brand' => 'Honda', 'fees' => [
        ['Safety & security package', 99700, true],
    ]],
    ['file' => 'Trumo_PentictonHyundai.csv', 'name' => 'Penticton Hyundai', 'address' => '448 Duncan Ave West', 'city' => 'Penticton', 'province' => 'BC', 'postal_code' => 'V2A 7N1', 'phone' => '250-712-0505',
        'brand' => 'Hyundai', 'fees' => [
        ['Documentation fee', 89900, true],
    ]],
    ['file' => 'Trumo_RICHMONDHYUNDAI.csv', 'name' => 'Richmond Hyundai', 'address' => '13251 Smallwood Pl', 'city' => 'Richmond', 'province' => 'BC', 'postal_code' => 'V6V 1W8', 'phone' => null,
        'brand' => 'Hyundai', 'fees' => [
        ['Deal processing fee', 79700, true],
        ['Detail & preparation', 67500, true],
    ]],
];
