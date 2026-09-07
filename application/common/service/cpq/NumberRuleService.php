<?php

namespace app\common\service\cpq;

use InvalidArgumentException;
use RuntimeException;
use think\Db;

/** 并发安全业务编号：计数器行在事务内 SELECT ... FOR UPDATE。 */
class NumberRuleService
{
    /** 为升级前数据库/首次调用幂等补齐默认规则。 */
    public function ensureRule($code, $name, $pattern, $periodType = 'year', $initialValue = 1)
    {
        $existing = Db::name('cpq_number_rule')->where('code', (string)$code)->find();
        if ($existing) return $existing;
        try {
            $id = Db::name('cpq_number_rule')->insertGetId([
                'code'=>(string)$code,'name'=>(string)$name,'pattern'=>(string)$pattern,
                'period_type'=>(string)$periodType,'initial_value'=>max(1,(int)$initialValue),
                'status'=>'enabled','createtime'=>time(),'updatetime'=>time(),
            ]);
            return Db::name('cpq_number_rule')->where('id', (int)$id)->find();
        } catch (\Throwable $exception) {
            $winner = Db::name('cpq_number_rule')->where('code', (string)$code)->find();
            if ($winner) return $winner;
            throw $exception;
        }
    }

    public function next($ruleCode, $scope = '', $at = null)
    {
        $rule = Db::name('cpq_number_rule')->where('code', (string)$ruleCode)->find();
        if (!$rule || $rule['status'] !== 'enabled') {
            throw new InvalidArgumentException('编号规则不存在或已停用');
        }
        $scope = trim((string)$scope);
        $timestamp = $at === null ? time() : (int)$at;
        $period = $this->periodKey((string)$rule['period_type'], $timestamp);

        for ($attempt = 0; $attempt < 3; $attempt++) {
            Db::startTrans();
            try {
                // 规则头锁覆盖“新 scope/新周期尚无计数器行”的首号竞争；
                // 已存在计数器时下方仍锁具体计数器行。
                $rule = Db::name('cpq_number_rule')->where('id', (int)$rule['id'])->lock(true)->find();
                if (!$rule || $rule['status'] !== 'enabled') {
                    throw new InvalidArgumentException('编号规则不存在或已停用');
                }
                $counter = Db::name('cpq_number_counter')
                    ->where('rule_id', (int)$rule['id'])
                    ->where('scope_key', $scope)
                    ->where('period_key', $period)
                    ->lock(true)
                    ->find();
                if ($counter) {
                    $sequence = (int)$counter['current_value'] + 1;
                    Db::name('cpq_number_counter')->where('id', (int)$counter['id'])->update([
                        'current_value' => $sequence,
                        'updatetime' => time(),
                    ]);
                } else {
                    $sequence = max(1, (int)$rule['initial_value']);
                    Db::name('cpq_number_counter')->insert([
                        'rule_id' => (int)$rule['id'],
                        'scope_key' => $scope,
                        'period_key' => $period,
                        'current_value' => $sequence,
                        'updatetime' => time(),
                    ]);
                }
                Db::commit();
                return $this->render((string)$rule['pattern'], $sequence, $scope, $timestamp);
            } catch (\Throwable $exception) {
                Db::rollback();
                // 两个新周期首请求并发时，唯一键冲突的请求重读锁行即可。
                if ($attempt < 2 && stripos($exception->getMessage(), 'duplicate') !== false) {
                    continue;
                }
                throw $exception;
            }
        }
        throw new RuntimeException('编号生成失败，请重试');
    }

    public function render($pattern, $sequence, $scope = '', $at = null)
    {
        $timestamp = $at === null ? time() : (int)$at;
        $value = strtr((string)$pattern, [
            '{YYYY}' => date('Y', $timestamp),
            '{MM}' => date('m', $timestamp),
            '{DD}' => date('d', $timestamp),
            '{SCOPE}' => strtoupper(preg_replace('/[^A-Za-z0-9_-]/', '', (string)$scope)),
        ]);
        return preg_replace_callback('/\{SEQ(\d{1,2})\}/', function ($match) use ($sequence) {
            $width = max(1, min(18, (int)$match[1]));
            return str_pad((string)$sequence, $width, '0', STR_PAD_LEFT);
        }, $value);
    }

    private function periodKey($type, $timestamp)
    {
        switch ($type) {
            case 'day': return date('Ymd', $timestamp);
            case 'month': return date('Ym', $timestamp);
            case 'year': return date('Y', $timestamp);
            case 'none': return '';
        }
        throw new InvalidArgumentException('不支持的编号周期');
    }
}
