<?php
/**
 * StorageAdapter.php —— 图床适配器接口（SPEC §7.2.1，签名已冻结，不得增删方法）
 */
if (!defined('APP_BOOT')) {
    http_response_code(404);
    exit;
}

interface StorageAdapter
{
    /**
     * 上传本地临时文件，并取回可用直链。
     * 对钛盘而言内部是两步（upload_cli → link_add），调用方无需感知。
     *
     * @return array{rel_path:string, remote_dkey:string, remote_name:string, direct_url:string, model:int}
     */
    public function put(string $tmpPath, string $remoteName, string $mime): array;

    /**
     * 撤销访问权限，可选同时删除文件本体。
     *
     * @param bool $deleteFile true = 连带删除文件，释放空间。
     *                         彻底删除内容时必须传 true，否则链接失效但空间永久占位。
     *                         【注意】TttttAdapter 内部固定带 delete=1，见 §7.2.3。
     * @return bool 成功与否。失败不抛异常 —— 调用方记录日志并继续
     *              （内容已不可见，残留链接是次要问题，不应阻塞删除流程）
     */
    public function revoke(array $asset, bool $deleteFile = false): bool;

    /**
     * 列出远端现存直链，用于自检与批量重建。
     * 钛盘此接口**分页**，实现内部须循环翻页直至取完。
     *
     * @return array|null 每项含 dkey / name / link / size / etime；不支持时返回 null
     */
    public function listLinks(): ?array;

    /** 存活探测。@return array{ok:bool, http_code:int|null, error:string|null} */
    public function probe(string $relPath): array;

    /**
     * 拼出可对外访问的完整 URL。
     * 优先取 $asset['direct_url']；为空则回退 cdn_base + rel_path 拼接。
     */
    public function url(array $asset, bool $thumb = false): ?string;

    public function supportsUpload(): bool;

    /** 能否撤销访问权限（钛盘为 true） */
    public function supportsRevoke(): bool;

    /** 撤销时能否连带删除文件本体以释放空间（钛盘为 true，靠 delete 参数） */
    public function supportsHardDelete(): bool;

    /** 图床是否自带缩略图能力（钛盘为 false） */
    public function supportsTransform(): bool;

    /** 图床是否自动剥离 EXIF（钛盘为 false → 前端必须剥离） */
    public function stripsExif(): bool;
}
