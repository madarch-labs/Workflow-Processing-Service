<?php

declare(strict_types=1);

namespace Wps\Notify;

/**
 * Links to CRM records: the URL model of the CRM lives in configuration (CRM_RECORD_URL_TEMPLATE), not in code.
 * Placeholders: {module}, {module_lower} (Leads → leads), {module_kebab} (AOS_Contracts → aos-contracts), {id}.
 * Relative links (CRM alerts) are made absolute for messengers with CRM_UI_BASE_URL.
 */
final class RecordLinks
{
    public const PROFILE_TEMPLATES = [
        'suitecrm8' => '#/{module_kebab}/record/{id}',
        'suitecrm7' => 'index.php?module={module}&action=DetailView&record={id}',
        'custom' => '',
    ];

    public function __construct(
        private readonly string $profile = 'suitecrm8',
        private readonly string $template = '',
        private readonly string $baseUrl = '',
    ) {
    }

    public function profile(): string
    {
        return $this->profile;
    }

    /** Relative (or absolute, as the template says) link; null when no template is known. */
    public function relative(string $module, string $id): ?string
    {
        $template = $this->template !== '' ? $this->template : (self::PROFILE_TEMPLATES[$this->profile] ?? '');
        if ($template === '') {
            return null;
        }
        return strtr($template, [
            '{module}' => rawurlencode($module),
            '{module_lower}' => rawurlencode(strtolower($module)),
            '{module_kebab}' => rawurlencode(str_replace('_', '-', strtolower($module))),
            '{id}' => rawurlencode($id),
        ]);
    }

    public function absolute(string $link): string
    {
        if (preg_match('#^https?://#i', $link) || $this->baseUrl === '') {
            return $link;
        }
        return rtrim($this->baseUrl, '/') . '/' . ltrim($link, '/');
    }

    public function isAbsolute(string $link): bool
    {
        return (bool)preg_match('#^https?://#i', $link);
    }
}
