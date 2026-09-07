<?php

namespace app\common\service\cpq;

use think\Db;
use think\Request;
use think\Session;

/**
 * CPQ 审计日志服务。
 *
 * 主数据与后续业务的关键写操作（create/update/delete/submit/publish/
 * expire/copy）在同一数据库事务内写入 cpq_audit_log；审计表只增不删，
 * 业务用户没有任何删除路径（方案 §2.1、§6.4）。
 */
class AuditLogService
{
    const ACTION_CREATE = 'create';
    const ACTION_UPDATE = 'update';
    const ACTION_DELETE = 'delete';
    const ACTION_SUBMIT = 'submit';
    const ACTION_PUBLISH = 'publish';
    const ACTION_EXPIRE = 'expire';
    const ACTION_COPY = 'copy';

    /**
     * 记录审计日志（在调用方事务内执行，随事务提交/回滚）。
     *
     * @param string $action      动作：create/update/delete/submit/publish/expire/copy
     * @param string $objectType  对象逻辑表名，如 cpq_product_series
     * @param int    $objectId    对象ID
     * @param array  $detail      变更明细（小体量键值，禁止写入敏感数据）
     * @param string $objectCode  对象业务编码
     * @return int
     */
    public function record($action, $objectType, $objectId, array $detail = [], $objectCode = '')
    {
        list($userId, $username) = $this->currentAdmin();
        return Db::name('cpq_audit_log')->insertGetId([
            'trace_id' => bin2hex(random_bytes(12)),
            'user_id' => $userId,
            'username' => $username,
            'action' => (string)$action,
            'object_type' => (string)$objectType,
            'object_id' => (int)$objectId,
            'object_code' => (string)$objectCode,
            'detail_json' => $detail ? json_encode($this->sanitize($detail), JSON_UNESCAPED_UNICODE) : null,
            'ip' => $this->clientIp(),
            'createtime' => time(),
        ]);
    }

    /**
     * 读取当前后台管理员；CLI/无会话上下文返回 system。
     *
     * @return array [user_id, username]
     */
    private function currentAdmin()
    {
        try {
            $admin = Session::get('admin');
            if (is_array($admin) && !empty($admin['id'])) {
                return [(int)$admin['id'], (string)($admin['username'] ?? '')];
            }
        } catch (\Throwable $exception) {
            // 无会话上下文（CLI 测试/命令行）时落到 system
        }
        return [0, 'system'];
    }

    /**
     * @return string
     */
    private function clientIp()
    {
        try {
            return (string)Request::instance()->ip();
        } catch (\Throwable $exception) {
            return '';
        }
    }

    /** 审计明细中必须递归替换为 [REDACTED] 的敏感键。 */
    const SENSITIVE_KEY_PATTERN = '/(?:password|passwd|authorization|api[_-]?key|apikey|private[_-]?key|client_secret|secret|token|credential_)/i';

    /**
     * 明细只保留标量与小数组；敏感键递归替换为 [REDACTED]，
     * 避免把凭证、令牌等秘密值写入日志，非敏感业务字段保持不变。
     *
     * @param array $detail
     * @return array
     */
    private function sanitize(array $detail)
    {
        $clean = [];
        foreach ($detail as $key => $value) {
            if (preg_match(self::SENSITIVE_KEY_PATTERN, (string)$key)) {
                $clean[$key] = '[REDACTED]';
                continue;
            }
            if (is_scalar($value) || $value === null) {
                $clean[$key] = $value;
            } elseif (is_array($value)) {
                $nested = $this->sanitize($value);
                if ($nested) {
                    $clean[$key] = $nested;
                }
            }
        }
        return $clean;
    }
}
