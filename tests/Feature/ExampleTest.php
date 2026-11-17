<?php

test('the home page opens the generate page', function () {
    $response = $this->get(route('home'));

    $response->assertRedirect(route('generations.create'));
});
