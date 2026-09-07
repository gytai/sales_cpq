<?php

namespace app\common\service\cpq;

use InvalidArgumentException;
use RuntimeException;
use think\Db;

/** Transactional outbox：事件幂等、单 worker 抢占、指数退避和死信。 */
class IntegrationEventService
{
    public function enqueue($eventType, $businessType, $businessId, array $payload, $configId = null, $sourceEventId = '')
    {
        $json = $this->canonicalJson($payload);
        $payloadHash = hash('sha256', $json);
        $key = hash('sha256', trim((string)$sourceEventId) !== '' ? 'source:' . $sourceEventId : implode('|', [$eventType,$businessType,$businessId,$payloadHash]));
        $existing = Db::name('cpq_integration_event')->where('event_key', $key)->find();
        if ($existing) return $existing;
        $maxRetries = 3;
        if ($configId) {
            $config = Db::name('cpq_integration_config')->where('id', (int)$configId)->find();
            if (!$config || $config['status'] !== 'enabled') throw new InvalidArgumentException('接口配置不存在或未启用');
            $maxRetries = (int)$config['max_retries'];
        }
        try {
            $id = Db::name('cpq_integration_event')->insertGetId([
                'event_key'=>$key,'config_id'=>$configId ? (int)$configId : null,'event_type'=>(string)$eventType,
                'business_type'=>(string)$businessType,'business_id'=>(string)$businessId,
                'payload_json'=>$json,'payload_hash'=>$payloadHash,'status'=>'pending','retry_count'=>0,
                'max_retries'=>$maxRetries,'next_retry_at'=>time(),'createtime'=>time(),'updatetime'=>time(),
            ]);
        } catch (\Throwable $exception) {
            $winner = Db::name('cpq_integration_event')->where('event_key', $key)->find();
            if ($winner) return $winner;
            throw $exception;
        }
        return Db::name('cpq_integration_event')->where('id', (int)$id)->find();
    }

    public function claimDue($now = null)
    {
        $now = $now === null ? time() : (int)$now;
        Db::startTrans();
        try {
            $event = Db::name('cpq_integration_event')->where('status', 'in', ['pending','failed'])->where('next_retry_at', '<=', $now)->order('id asc')->lock(true)->find();
            if (!$event) { Db::commit(); return null; }
            $changed = Db::name('cpq_integration_event')->where('id', (int)$event['id'])->where('status', $event['status'])->update(['status'=>'processing','locked_at'=>$now,'updatetime'=>$now]);
            Db::commit();
            return $changed ? Db::name('cpq_integration_event')->where('id', (int)$event['id'])->find() : null;
        } catch (\Throwable $exception) { Db::rollback(); throw $exception; }
    }

    /** 仅通过注入 transport 发送，测试/默认路径绝不写真实 ERP。 */
    public function deliver(array $event, callable $transport, IntegrationCredentialService $vault)
    {
        if ($event['status'] !== 'processing') throw new InvalidArgumentException('事件未被 worker 抢占');
        $config = Db::name('cpq_integration_config')->where('id', (int)$event['config_id'])->find();
        if (!$config || $config['status'] !== 'enabled') return $this->markFailed((int)$event['id'], '接口配置不可用');
        $body = (string)$event['payload_json'];
        $headers = (new IntegrationAuthService())->authorizationHeaders($config, $vault->credentials((int)$config['id']), $body);
        try {
            $response = $transport([
                'url'=>rtrim((string)$config['base_url'], '/') . '/events', 'method'=>'POST',
                'timeout_ms'=>(int)$config['timeout_ms'], 'headers'=>$headers + ['Content-Type'=>'application/json','X-CPQ-Event-Key'=>$event['event_key']], 'body'=>$body,
            ]);
            $status = (int)($response['status'] ?? 0);
            if ($status < 200 || $status >= 300) throw new RuntimeException('接口返回 HTTP ' . $status);
            Db::name('cpq_integration_event')->where('id', (int)$event['id'])->update(['status'=>'succeeded','delivered_at'=>time(),'last_error'=>'','updatetime'=>time()]);
            return Db::name('cpq_integration_event')->where('id', (int)$event['id'])->find();
        } catch (\Throwable $exception) {
            return $this->markFailed((int)$event['id'], $exception->getMessage());
        }
    }

    public function markFailed($id, $message, $now = null)
    {
        $now = $now === null ? time() : (int)$now;
        $event = Db::name('cpq_integration_event')->where('id', (int)$id)->find();
        if (!$event) throw new InvalidArgumentException('集成事件不存在');
        $retry = (int)$event['retry_count'] + 1;
        $dead = $retry > (int)$event['max_retries'];
        Db::name('cpq_integration_event')->where('id', (int)$id)->update([
            'status'=>$dead ? 'dead' : 'failed','retry_count'=>$retry,
            'next_retry_at'=>$dead ? null : $now + min(3600, 30 * (2 ** max(0, $retry - 1))),
            'locked_at'=>null,'last_error'=>$this->sanitizeError($message),'updatetime'=>$now,
        ]);
        return Db::name('cpq_integration_event')->where('id', (int)$id)->find();
    }

    private function sanitizeError($message)
    {
        $message = preg_replace('/authorization\s*[:=]\s*bearer\s+[^\s,;]+/i', 'authorization=[REDACTED]', (string)$message);
        return mb_substr(preg_replace('/(authorization|token|password|secret|api[_-]?key)\s*[:=]\s*[^\s,;]+/i', '$1=[REDACTED]', $message), 0, 500);
    }

    private function canonicalJson(array $value)
    {
        $sort = function (&$item) use (&$sort) {
            if (!is_array($item)) return;
            foreach ($item as &$nested) $sort($nested);
            unset($nested);
            if (array_keys($item) !== range(0, count($item) - 1)) ksort($item);
        };
        $sort($value);
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
