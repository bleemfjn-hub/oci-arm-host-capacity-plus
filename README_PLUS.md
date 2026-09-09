# oci-arm-host-capacity-plus

> 甲骨文云（OCI）Always Free ARM 实例自动抢购脚本，**先抢小配置，抢到后自动升级**。

基于 [hitrov/oci-arm-host-capacity](https://github.com/hitrov/oci-arm-host-capacity) 改造，增加了三个实用特性：

| 特性 | 说明 |
|------|------|
| 🎯 **先小后大** | 用 1 OCPU / 6 GB 抢（命中率高），抢到后自动尝试升级到 2 OCPU / 12 GB |
| 🔔 **Telegram 推送** | 抢到 / 升级成功 / 升级失败，三种情况都推送，含公网 IP 和 SSH 命令 |
| 🚦 **429 退避** | 遇到 `TooManyRequests` 自动冷却，避免硬撞限流反而降低成功率 |

---

## 为什么需要「先小后大」

甲骨文东京、首尔等热门区域的 A1.Flex 容量长期爆满，直接抢 2/12 或 4/24 往往几个月都抢不到。

但 **1 OCPU / 6 GB 的请求更容易被满足**——调度器需要找到的连续空闲资源更少。

所以策略是：

```
先用 1/6 抢到保底 → 立刻推送通知 → 自动尝试升级到 2/12
                                    ├─ 成功 → 推送升级通知
                                    └─ 失败 → 保持 1/6 可用，稍后可重试

```

**这是个帕累托改进**：最坏情况你有一个能用的 1/6 实例，最好情况自动升到满配。

---

## 快速开始

### 1. 准备 OCI 凭证

在 OCI Console → 右上角头像 → **My Profile → API Keys → Add API Key**，下载私钥并记录：

- `user` OCID
- `tenancy` OCID
- `fingerprint`
- 私钥文件路径

### 2. 准备 VCN / Subnet

OCI Console → Networking → Virtual Cloud Networks → **Start VCN Wizard** → 创建带 Public Subnet 的 VCN。

记录 **Subnet OCID**。

### 3. 生成 SSH 密钥

```bash
ssh-keygen -t ed25519 -f ~/.ssh/oci_instance -N ''

```

公钥内容填到 `.env` 的 `OCI_SSH_PUBLIC_KEY`。

### 4. 安装

```bash
git clone https://github.com/bleemfjn-hub/oci-arm-host-capacity-plus.git
cd oci-arm-host-capacity-plus
composer install
cp .env.example .env
vim .env

```

### 5. 配置 `.env`

关键项（完整说明见 `.env.example`）：

```ini

# ---- 抢购规格（小配置，命中率高）----
OCI_OCPUS=1
OCI_MEMORY_IN_GBS=6

# ---- 抢到后自动升级目标（留空则禁用自动升级）----
OCI_RESIZE_TARGET_OCPUS=2
OCI_RESIZE_TARGET_MEMORY_IN_GBS=12

# ---- 429 退避（秒）----
TOO_MANY_REQUESTS_TIME_WAIT=600

# ---- 缓存可用域，减少 API 调用 ----
CACHE_AVAILABILITY_DOMAINS=1

```

### 6. 手动跑一次

```bash
php index.php

```

- `Out of host capacity` → 正常，没库存，继续重试
- `429 backoff active: Will retry after N seconds` → 触发限流，冷却中
- `🎉 抢到 ARM 实例了！` → 成功，Telegram 会收到推送

### 7. 挂 cron

```bash
crontab -e

```

```cron
*/2 * * * * cd /root/oci-arm-host-capacity-plus && /usr/bin/php index.php >> oci.log 2>&1

```

**建议 2 分钟一次**。低于 30 秒会撞 429，反而降低成功率。

---

## 配置项说明

### 抢购规格

| 变量 | 说明 |
|------|------|
| `OCI_OCPUS` | 抢购时的 OCPU 数 |
| `OCI_MEMORY_IN_GBS` | 抢购时的内存（GB） |
| `OCI_SHAPE` | 固定 `VM.Standard.A1.Flex` |
| `OCI_MAX_INSTANCES` | 该规格最多保留几个实例，默认 1 |

### 自动升级

| 变量 | 说明 |
|------|------|
| `OCI_RESIZE_TARGET_OCPUS` | 升级目标 OCPU，留空或 0 则禁用 |
| `OCI_RESIZE_TARGET_MEMORY_IN_GBS` | 升级目标内存，留空或 0 则禁用 |

> ⚠️ **免费额度提醒**：甲骨文从 2026.6.15 起把 ARM 免费配额从 4 OCPU / 24 GB 减半到 **2 OCPU / 12 GB**。
> 升级目标不要超过你的免费上限，否则会产生费用。请到 OCI Console → Governance → **Limits, Quotas, and Usage** 确认。

### 限流与缓存

| 变量 | 说明 |
|------|------|
| `TOO_MANY_REQUESTS_TIME_WAIT` | 遇到 429 后冷却多少秒，推荐 600 |
| `CACHE_AVAILABILITY_DOMAINS` | 设为 1 缓存可用域列表，减少 API 调用 |

### Telegram 推送

| 变量 | 说明 |
|------|------|
| `TELEGRAM_BOT_API_KEY` | Bot Token（找 @BotFather 申请） |
| `TELEGRAM_USER_ID` | 你的 User ID（找 @userinfobot 查） |

---

## 推送示例

**抢到实例：**

```
🎉 抢到 ARM 实例了！
─────────────────
📌 实例名: instance-20260909-1530
🆔 OCID: ocid1.instance.oc1.ap-tokyo-1.xxx
📐 规格: VM.Standard.A1.Flex (1 OCPU / 6GB)
📍 可用域: xxxx:AP-TOKYO-1-AD-1
🟢 状态: PROVISIONING
🌐 公网IP: 123.45.67.89
🕐 创建时间: 2026-09-09T06:30:00.000Z
🔑 SSH: ssh -i <your_key> ubuntu@123.45.67.89

```

**升级成功：**

```
⬆️ 实例已升级！
─────────────────
📌 实例名: instance-20260909-1530
📐 新规格: 2 OCPU / 12GB
🌐 公网IP: 123.45.67.89
🔑 SSH: ssh -i <your_key> ubuntu@123.45.67.89

```

**升级失败（实例仍可用）：**

```
⚠️ 实例已抢到（1 OCPU / 6GB），但升级到 2/12 失败：
{"code": "InternalError", "message": "Out of host capacity."}

实例可正常使用，稍后可重试升级。

```

---

## 抢到之后

1. **申请保留公网 IP**（避免重启后 IP 变化）
   OCI Console → Networking → Reserved Public IPs → Create
2. **开放安全列表端口**
   VCN → Security Lists → Add Ingress Rules
3. **实例内放行 iptables**（甲骨文默认 INPUT DROP）

   ```bash
   sudo iptables -I INPUT -p tcp --dport <port> -j ACCEPT
   sudo netfilter-persistent save

   ```

---

## 提高成功率的其他手段

| 手段 | 效果 | 说明 |
|------|------|------|
| **升级 PAYG** | ⭐⭐⭐⭐⭐ | 官方给 PAYG 用户实例启动优先级，免费额度内不扣费 |
| **先小后大** | ⭐⭐⭐⭐ | 本项目的核心特性 |
| **降低频率 + 退避** | ⭐⭐⭐ | 避免 429 堆积 |
| **换区** | ⭐⭐⭐ | 大阪 / 首尔 / 新加坡可能比东京好抢 |
| **换脚本** | ⭐ | **没用**。所有脚本都调同一个 `LaunchInstance` API，瓶颈在服务端 |

---

## 与原项目的差异

```diff
+ src/OciApi.php
+   + updateInstanceShape()    # PUT /instances/{id} 修改 shapeConfig
+   ~ assignPublicIp: false → true   # 自动分配公网 IP
+
+ index.php
+   + catch(TooManyRequestsWaiterException)  # 429 退避不再抛 Fatal error
+   + 成功分支：人类可读的推送摘要（含公网 IP + SSH 命令）
+   + 自动升级逻辑（可配置目标，失败不影响实例可用）

```

---

## License

MIT（沿用原项目）

## 致谢

- [hitrov/oci-arm-host-capacity](https://github.com/hitrov/oci-arm-host-capacity) — 原始项目
- [ghkim887/oci-creator](https://github.com/ghkim887/oci-creator) — 「先小后大」策略的灵感来源
