<?php

namespace App\Modules\Shared\Enums;

enum MessageType: string
{
    case TEXT = 'text';
    case IMAGE = 'image';
    case AUDIO = 'audio';
    case DOCUMENT = 'document';
    case VIDEO = 'video';
    case STICKER = 'sticker';

    public function label(): string
    {
        return match($this) {
            self::TEXT => 'Text',
            self::IMAGE => 'Image',
            self::AUDIO => 'Audio',
            self::DOCUMENT => 'Document',
            self::VIDEO => 'Video',
            self::STICKER => 'Sticker',
        };
    }
}
