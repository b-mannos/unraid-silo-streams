<?php
declare(strict_types=1);

function silo_widget_markup(int $pollInterval = 5): string
{
    return '<div class="silo-streams" data-silo-streams data-poll-interval="' . max(5, min(60, $pollInterval)) . '"></div>';
}
