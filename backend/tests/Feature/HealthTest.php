<?php

it('responde no endpoint de health', function () {
    $this->get('/up')->assertOk();
});
