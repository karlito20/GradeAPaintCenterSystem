<?php

namespace Tests\Feature\Auth;

test('public registration is disabled for internal application', function () {
    $response = $this->get('/register');

    $response->assertNotFound();
});
