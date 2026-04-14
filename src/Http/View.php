<?php

declare(strict_types=1);

namespace LocalChat\Http;

final class View
{
    public static function render(string $template, array $viewModel = []): void
    {
        $templatePath = BASE_PATH . '/templates/' . $template . '.php';

        if (!is_file($templatePath)) {
            http_response_code(500);
            echo 'Template not found.';

            return;
        }

        extract($viewModel, EXTR_SKIP);
        require $templatePath;
    }
}

function render(string $template, array $viewModel = []): void
{
    View::render($template, $viewModel);
}
