<?php

it('redirects root to dashboard', function () {
    $response = $this->get('/');

    $response->assertRedirect('/dashboard');
});
