<?php

namespace App\Services\DomainMutation;

/** Revision payloads are deliberately full replacement; nullable fields therefore mean clear, never omitted. */
enum RevisionPayloadMode: string
{
    case FullReplacement = 'full_replacement';
}
