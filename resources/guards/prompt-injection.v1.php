<?php

declare(strict_types=1);

/**
 * Prompt-injection patterns checked against a question before retrieval: name => case-insensitive regex.
 *
 * @return array<string, string>
 */
return [
    'ignore-previous' => '/\b(ignore|disregard|forget|override)\s+(all\s+)?(of\s+)?(the\s+|your\s+|my\s+|these\s+|those\s+)?(previous|prior|above|earlier|preceding|original)\s+(instructions?|prompts?|rules|directions?)\b/iu',
    'reveal-prompt' => '/\b(reveal|show|print|repeat|display|output|leak|tell)\s+(me\s+|us\s+)?(your|the)\s+(full\s+|exact\s+)?(system|initial|original|hidden)\s+(prompt|instructions?)\b/iu',
    'ask-prompt' => '/\b(what|which)\s+(is|are|were)\s+your\s+(system\s+prompt|instructions|rules)\b/iu',
    'role-override' => '/\b(you are now|from now on you are|pretend (to be|you are)|act as (if you are |an? )?(?!a\s+guide)(unrestricted|jailbroken|different|evil|dan\b))/iu',
    'new-instructions' => '/\b(new|updated|revised)\s+(instructions?|rules?|system prompt)\s*:/iu',
    'developer-mode' => '/\b(enable|enter|switch to|activate|turn on)\s+(developer|god|sudo|dan|admin)\s+mode\b/iu',
];
