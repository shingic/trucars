<?php

namespace App\Support\Email;

/**
 * The look of every email, in one place. Email clients ignore stylesheets,
 * so the components in resources/views/mail/components inline these.
 */
final class Theme
{
    public const FONT = "font-family:'Barlow', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;";

    public const MONO = "font-family:'SFMono-Regular', Consolas, 'Liberation Mono', Menlo, monospace;";

    public const PAGE = '#F1F3F7';   // behind the card, and callout fill

    public const CARD = '#FFFFFF';

    public const LINE = '#E3E6ED';   // card border, table rules

    public const INK = '#0A1435';    // headings, values, button

    public const TEXT = '#3D4760';   // body copy

    public const MUTED = '#6B7489';  // labels, footer

    public const ACCENT = '#1B84FF'; // lane line, callout bar, links

    public const GOOD = '#0B7355';   // price drops, "less"
}
