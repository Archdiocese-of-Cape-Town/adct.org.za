<?php

namespace ADCT\ParishIntake\Parsing\Input;

final class Attachment
{
    private string $name;
    private string $mimeType;
    private ?string $path;

    public function __construct(string $name, string $mimeType = '', ?string $path = null)
    {
        $this->name = $name;
        $this->mimeType = $mimeType;
        $this->path = $path;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getMimeType(): string
    {
        return $this->mimeType;
    }

    public function getPath(): ?string
    {
        return $this->path;
    }
}
