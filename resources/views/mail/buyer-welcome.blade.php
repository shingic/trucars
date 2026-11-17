<x-email::layout title="Welcome to Trumo"
    preheader="Your Trumo account is ready. Browse certified cars, reserve in minutes, and track everything in My Garage. Creating an account never starts a purchase."
    :link="route('shop')"
    reason="Sent because you created a Trumo account.">
    <x-email::heading>Welcome, {{ $firstName }}</x-email::heading>
    <x-email::text>Your account is ready. It's where your reservations, your deal status and, once the keys are yours, your garage all live.</x-email::text>
    <x-email::callout lead="No purchase started:">creating an account is free. Reserving a car is always a separate step you choose to take.</x-email::callout>
    <x-email::section>What you can do now</x-email::section>
    <x-email::steps :items="[
        'Browse cars with all-in pricing. Certified cars list every inspection point, and the number you see is the number you pay (before sales tax and licensing).',
        'Reserve in minutes. A $150 fully refundable deposit holds your exact car, and it comes off the purchase price. It isn\'t an extra charge.',
        'Track everything in My Garage. Your deal status, documents and financing stay in one place, start to finish.',
    ]" />
    <x-email::button :url="route('shop')">Browse certified cars</x-email::button>
    <x-email::text small>Trumo handles your reservation and checkout. The dealership owns the vehicle, the financing and the handover, and confirms all final figures with you before anything is signed.</x-email::text>
</x-email::layout>
