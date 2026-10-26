<?php

declare(strict_types=1);

namespace Wps\Template;

/**
 * How substituted values are escaped. Chosen by the consumer of the template (the field), never by its author.
 */
enum EscapeMode: string
{
    /** as is: modify, e-mail subject, notification text */
    case Text = 'text';
    /** CR / LF removed: HTTP and mail headers (header injection) */
    case Header = 'header';
    /** htmlspecialchars: HTML e-mail body */
    case Html = 'html';
    /** rawurlencode; no substitutions in scheme / host / port (SSRF) */
    case Url = 'url';
    /** renderStructure(): a leaf made of one substitution keeps the value type */
    case Json = 'json';
}
