<?php
/**
 * StorageException.php —— 图床统一异常，携带映射后的错误码（SPEC §4.1）
 *
 * 错误码来自 SPEC §7.2.2 的 status 映射表：
 *   STORAGE_ERROR / UPLOAD_TOO_LARGE / STORAGE_BUSY / STORAGE_FULL / STORAGE_QUOTA / STORAGE_AUTH
 * 另外提供 rawStatus / rawResponse 供日志排查（不回显给前端）。
 */
if (!defined('APP_BOOT')) {
    http_response_code(404);
    exit;
}

final class StorageException extends RuntimeException
{
    private string $errorCode;
    private int $httpStatus;
    private ?string $rawStatus;
    private ?string $rawResponse;

    public function __construct(
        string $errorCode,
        string $message,
        int $httpStatus = 400,
        ?string $rawStatus = null,
        ?string $rawResponse = null
    ) {
        parent::__construct($message);
        $this->errorCode   = $errorCode;
        $this->httpStatus  = $httpStatus;
        $this->rawStatus   = $rawStatus;
        // 原始响应只保留前 500 字节（SPEC §7.2.2：未知错误记原始响应前 500 字节）
        $this->rawResponse = $rawResponse === null ? null : substr($rawResponse, 0, 500);
    }

    public function errorCode(): string   { return $this->errorCode; }
    public function httpStatus(): int      { return $this->httpStatus; }
    public function rawStatus(): ?string   { return $this->rawStatus; }
    public function rawResponse(): ?string { return $this->rawResponse; }

    /** 供 ErrorHandler 记录的结构化上下文 */
    public function context(): array
    {
        return [
            'code'         => $this->errorCode,
            'raw_status'   => $this->rawStatus,
            'raw_response' => $this->rawResponse,
        ];
    }
}
