<?php

namespace Ssntpl\Neev\Exceptions;

use Exception;

/**
 * Thrown when `magic_link.bind_to_browser` is enabled but the generating
 * request carries no binding source (no context['binding'], `binding` field,
 * X-Device-Id header, or session).
 *
 * Generation fails loudly here because a link stored without a fingerprint
 * could never satisfy the binding check at redemption — it would be dead on
 * arrival, and the user, not the operator, would discover it.
 */
class MagicLinkBindingException extends Exception
{
    protected $message = 'Cannot generate a browser-bound magic link: the request has no binding source. Send an X-Device-Id header or a binding value, or disable neev.magic_link.bind_to_browser.';
}
