<?php

namespace Tests\Feature\Auth;

test('password reset routes are disabled', function () {
    $response = $this->get('/forgot-password');

    $response->assertNotFound();
});
