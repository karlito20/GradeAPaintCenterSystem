<?php

test('email verification routes are disabled', function () {
    $response = $this->get('/verify-email');

    $response->assertNotFound();
});
