<?php

namespace ADCT\ParishIntake\Core\Parsing\Input;

final class Attachment
{
    private string $name;
    private string $mimeType;
    private ?string $path;
    private ?string $contentReference;
    private ?string $contentId;

    public function __construct(
        string $name,
        string $mimeType = '',
        ?string $path = null,
        ?string $contentReference = null,
        ?string $contentId = null
    ) {
        $this->name = $name;
        $this->mimeType = $mimeType;
        $this->path = $path;
        $this->contentReference = $contentReference;
        $this->contentId = $contentId;
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

    public function getContentReference(): ?string
    {
        return $this->contentReference;
    }

    public function getContentId(): ?string
    {
        return $this->contentId;
    }
}
