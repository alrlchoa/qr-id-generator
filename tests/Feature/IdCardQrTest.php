<?php

use App\Models\IdCard;
use App\Models\User;

test('the QR preview page renders an SVG containing the control number as text', function () {
    bootstrapSystem();
    $this->actingAs(User::factory()->admin()->create());

    $card = IdCard::factory()->create(['control_number' => '12345678']);

    $this->get(route('id-cards.qr', $card))
        ->assertOk()
        ->assertSee('<svg', false)
        ->assertSee('12345678');
});

test('the QR preview page is reachable by a Reader too', function () {
    bootstrapSystem();
    $this->actingAs(User::factory()->reader()->create());

    $card = IdCard::factory()->create();

    $this->get(route('id-cards.qr', $card))->assertOk();
});
