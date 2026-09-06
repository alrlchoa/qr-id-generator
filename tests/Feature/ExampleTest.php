<?php

it('returns a successful response', function () {
    bootstrapSystem();

    $response = $this->get('/');

    $response->assertStatus(200);
});
