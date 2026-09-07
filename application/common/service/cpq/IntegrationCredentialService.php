<?php

namespace app\common\service\cpq;

use InvalidArgumentException;
use RuntimeException;
use think\Db;
use think\Env;

/** CRM/ERP 配置与 AES-256-GCM 凭证保险箱；列表/详情永不回显凭证明文。 */
class IntegrationCredentialService
{
    private $key;

    public function __construct($key = null)
    {
        $source = $key === null ? (string)Env::get('cpq.integration_key', '') : (string)$key;
        if ($source === '') throw new RuntimeException('未配置 CPQ_INTEGRATION_KEY');
        $this->key = hash('sha256', $source, true);
    }

    public function save(array $input, $id = null)
    {
        $isNew = $id === null;
        $current = $isNew ? null : Db::name('cpq_integration_config')->where('id', (int)$id)->find();
        if (!$isNew && !$current) throw new InvalidArgumentException('接口配置不存在');
        $authType = (string)($input['auth_type'] ?? ($current['auth_type'] ?? 'none'));
        if (!in_array($authType, ['none','hmac','oauth2'], true)) throw new InvalidArgumentException('不支持的认证方式');
        $url = trim((string)($input['base_url'] ?? ($current['base_url'] ?? '')));
        if ($url !== '' && filter_var($url, FILTER_VALIDATE_URL) === false) throw new InvalidArgumentException('接口地址无效');
        $data = array_intersect_key($input, array_flip(['code','name','system_type','base_url','auth_type','hmac_algorithm','timeout_ms','max_retries','status']));
        $defaults = ['system_type'=>'other','base_url'=>'','auth_type'=>'none','hmac_algorithm'=>'sha256','timeout_ms'=>5000,'max_retries'=>3,'status'=>'disabled'];
        if ($isNew) $data += $defaults;
        $data['auth_type'] = $authType;
        $data['base_url'] = $url;
        foreach (['hmac_algorithm','timeout_ms','max_retries','status'] as $field) {
            if (!array_key_exists($field, $data) && !$isNew) $data[$field] = $current[$field];
        }
        $data['timeout_ms'] = max(100, min(60000, (int)$data['timeout_ms']));
        $data['max_retries'] = max(0, min(10, (int)$data['max_retries']));
        if (!in_array($data['hmac_algorithm'], ['sha256','sha512'], true)) throw new InvalidArgumentException('HMAC 算法仅支持 sha256/sha512');
        if (array_key_exists('credentials', $input)) {
            if (!is_array($input['credentials'])) throw new InvalidArgumentException('凭证必须是对象');
            $data += $this->encrypt($input['credentials']);
        } elseif ($id === null && $authType !== 'none') {
            throw new InvalidArgumentException('启用认证时必须设置凭证');
        }
        $data['updatetime'] = time();
        if ($id === null) {
            $data['createtime'] = time();
            $id = Db::name('cpq_integration_config')->insertGetId($data);
        } else {
            Db::name('cpq_integration_config')->where('id', (int)$id)->update($data);
        }
        (new AuditLogService())->record($isNew ? 'integration_config_create' : 'integration_config_update', 'cpq_integration_config', (int)$id, [
            'auth_type'=>$data['auth_type'], 'credentials_reset'=>array_key_exists('credentials', $input),
        ]);
        return $this->publicConfig(Db::name('cpq_integration_config')->where('id', (int)$id)->find());
    }

    public function get($id)
    {
        $row = Db::name('cpq_integration_config')->where('id', (int)$id)->find();
        if (!$row) throw new InvalidArgumentException('接口配置不存在');
        return $this->publicConfig($row);
    }

    public function credentials($id)
    {
        $row = Db::name('cpq_integration_config')->where('id', (int)$id)->find();
        if (!$row) throw new InvalidArgumentException('接口配置不存在');
        if ((string)$row['credential_ciphertext'] === '') return [];
        $plaintext = openssl_decrypt(
            base64_decode($row['credential_ciphertext'], true), 'aes-256-gcm', $this->key,
            OPENSSL_RAW_DATA, base64_decode($row['credential_nonce'], true), base64_decode($row['credential_tag'], true),
            'cpq-integration:' . (string)$row['credential_key_version']
        );
        if ($plaintext === false) throw new RuntimeException('接口凭证解密失败');
        $decoded = json_decode($plaintext, true);
        if (!is_array($decoded)) throw new RuntimeException('接口凭证格式损坏');
        return $decoded;
    }

    /**
     * 公开配置：递归移除所有 credential_* 字段，仅额外给出
     * credentials_configured 布尔状态，永不回显凭证明文/密文。
     *
     * @param array $row
     * @return array
     */
    public function publicConfig(array $row)
    {
        $configured = !empty($row['credential_ciphertext']);
        unset($row['credential_ciphertext'], $row['credential_nonce'], $row['credential_tag'], $row['credential_key_version']);
        $row = $this->stripCredentialFields($row);
        $row['credentials_configured'] = $configured;
        return $row;
    }

    /**
     * @param array $data
     * @return array
     */
    private function stripCredentialFields(array $data)
    {
        foreach ($data as $key => $value) {
            if (strpos((string)$key, 'credential_') === 0) {
                unset($data[$key]);
                continue;
            }
            if (is_array($value)) {
                $data[$key] = $this->stripCredentialFields($value);
            }
        }
        return $data;
    }

    private function encrypt(array $credentials)
    {
        $nonce = random_bytes(12);
        $tag = '';
        $version = 'v1';
        $ciphertext = openssl_encrypt(json_encode($credentials, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'aes-256-gcm', $this->key, OPENSSL_RAW_DATA, $nonce, $tag, 'cpq-integration:' . $version);
        if ($ciphertext === false) throw new RuntimeException('接口凭证加密失败');
        return [
            'credential_ciphertext'=>base64_encode($ciphertext),
            'credential_nonce'=>base64_encode($nonce),
            'credential_tag'=>base64_encode($tag),
            'credential_key_version'=>$version,
        ];
    }
}
