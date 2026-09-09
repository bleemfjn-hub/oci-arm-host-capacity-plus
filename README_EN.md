# oci-arm-host-capacity-plus

[中文](README.md) | **English**

> Oracle Cloud (OCI) Always Free ARM instance auto-grabber with a **grab-small-then-grow** strategy.

[![CI](https://github.com/bleemfjn-hub/oci-arm-host-capacity-plus/actions/workflows/ci.yml/badge.svg)](https://github.com/bleemfjn-hub/oci-arm-host-capacity-plus/actions/workflows/ci.yml)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://opensource.org/licenses/MIT)
[![PHP](https://img.shields.io/badge/PHP-7.0%20--%208.3-blue.svg)](https://www.php.net/)

Forked from [hitrov/oci-arm-host-capacity](https://github.com/hitrov/oci-arm-host-capacity) with three practical additions:

| Feature | Description |
|---------|-------------|
| 🎯 **Grab small, then grow** | Grab with 1 OCPU / 6 GB (much higher hit rate), then auto-resize to 2 OCPU / 12 GB |
| 🔔 **Telegram notifications** | Pushes on grab success / resize success / resize failure, including public IP and SSH command |
| 🚦 **429 backoff** | Cooldown on `TooManyRequests` instead of hammering the API and making things worse |

---

## Why "grab small, then grow"?

A1.Flex capacity in popular regions (Tokyo, Seoul, etc.) is chronically exhausted. Trying to grab 2/12 or 4/24 directly often fails for months.

But a **1 OCPU / 6 GB request is far easier to satisfy** — the scheduler needs to find fewer contiguous free resources.

So the strategy is:

```
Grab 1/6 as a fallback  →  push notification  →  auto-resize to 2/12
                                                  ├─ success → push resize notification
                                                  └─ failure → keep 1/6 usable, retry later

```

**This is a Pareto improvement**: worst case you have a working 1/6 instance, best case it auto-upgrades to the full free tier.

---

## Quick start

### 1. Prepare OCI credentials

OCI Console → avatar (top right) → **My Profile → API Keys → Add API Key**. Download the private key and note down:

- `user` OCID
- `tenancy` OCID
- `fingerprint`
- private key file path

### 2. Prepare VCN / Subnet

OCI Console → Networking → Virtual Cloud Networks → **Start VCN Wizard** → create a VCN with a Public Subnet.

Note the **Subnet OCID**.

### 3. Generate an SSH key

```bash
ssh-keygen -t ed25519 -f ~/.ssh/oci_instance -N ''

```

Put the public key contents into `OCI_SSH_PUBLIC_KEY` in `.env`.

### 4. Install

```bash
git clone https://github.com/bleemfjn-hub/oci-arm-host-capacity-plus.git
cd oci-arm-host-capacity-plus
composer install
cp .env.example .env
vim .env

```

### 5. Configure `.env`

Key settings (see `.env.example` for the full list):

```ini

# ---- Grab shape (small = higher hit rate) ----
OCI_OCPUS=1
OCI_MEMORY_IN_GBS=6

# ---- Auto-resize target (leave empty to disable) ----
OCI_RESIZE_TARGET_OCPUS=2
OCI_RESIZE_TARGET_MEMORY_IN_GBS=12

# ---- 429 backoff (seconds) ----
TOO_MANY_REQUESTS_TIME_WAIT=600

# ---- Cache availability domains to reduce API calls ----
CACHE_AVAILABILITY_DOMAINS=1

```

### 6. Run once manually

```bash
php index.php

```

- `Out of host capacity` → normal, no capacity, keep retrying
- `429 backoff active: Will retry after N seconds` → rate limited, cooling down
- `🎉 抢到 ARM 实例了！` → success, Telegram will notify you

### 7. Add to cron

```bash
crontab -e

```

```cron
*/2 * * * * cd /root/oci-arm-host-capacity-plus && /usr/bin/php index.php >> oci.log 2>&1

```

**2 minutes is recommended.** Anything below 30 seconds will hit 429 and actually lower your success rate.

---

## Configuration reference

### Grab shape

| Variable | Description |
|----------|-------------|
| `OCI_OCPUS` | OCPU count when grabbing |
| `OCI_MEMORY_IN_GBS` | Memory in GB when grabbing |
| `OCI_SHAPE` | Fixed to `VM.Standard.A1.Flex` |
| `OCI_MAX_INSTANCES` | Max instances to keep for this shape, default 1 |

### Auto-resize

| Variable | Description |
|----------|-------------|
| `OCI_RESIZE_TARGET_OCPUS` | Target OCPU. Empty or 0 disables auto-resize |
| `OCI_RESIZE_TARGET_MEMORY_IN_GBS` | Target memory in GB. Empty or 0 disables auto-resize |

> ⚠️ **Free tier warning**: Oracle halved the Always Free ARM quota from 4 OCPU / 24 GB to **2 OCPU / 12 GB** on 2026-06-15.
> Do not set a target above your free limit, or you will be billed. Check OCI Console → Governance → **Limits, Quotas, and Usage**.

### Rate limiting and caching

| Variable | Description |
|----------|-------------|
| `TOO_MANY_REQUESTS_TIME_WAIT` | Cooldown seconds after a 429, 600 recommended |
| `CACHE_AVAILABILITY_DOMAINS` | Set to 1 to cache the availability-domain list and reduce API calls |

### Telegram notifications

| Variable | Description |
|----------|-------------|
| `TELEGRAM_BOT_API_KEY` | Bot token (get one from @BotFather) |
| `TELEGRAM_USER_ID` | Your user ID (get it from @userinfobot) |

---

## Notification examples

**Instance grabbed:**

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

**Resize succeeded:**

```
⬆️ 实例已升级！
─────────────────
📌 实例名: instance-20260909-1530
📐 新规格: 2 OCPU / 12GB
🌐 公网IP: 123.45.67.89
🔑 SSH: ssh -i <your_key> ubuntu@123.45.67.89

```

**Resize failed (instance still usable):**

```
⚠️ 实例已抢到（1 OCPU / 6GB），但升级到 2/12 失败：
{"code": "InternalError", "message": "Out of host capacity."}
实例可正常使用，稍后可重试升级。

```

---

## After you grab an instance

1. **Reserve a public IP** (so it survives reboots)
   OCI Console → Networking → Reserved Public IPs → Create

2. **Open ports in the security list**
   VCN → Security Lists → Add Ingress Rules

3. **Allow traffic in iptables inside the instance** (Oracle defaults to INPUT DROP)

   ```bash
   sudo iptables -I INPUT -p tcp --dport <port> -j ACCEPT
   sudo netfilter-persistent save

   ```

---

## Other ways to improve your success rate

| Method | Impact | Notes |
|--------|--------|-------|
| **Upgrade to PAYG** | ⭐⭐⭐⭐⭐ | Oracle gives PAYG accounts higher instance launch priority. No charge within free limits |
| **Grab small, then grow** | ⭐⭐⭐⭐ | The core feature of this project |
| **Lower frequency + backoff** | ⭐⭐⭐ | Avoids 429 pileup |
| **Switch region** | ⭐⭐⭐ | Osaka / Seoul / Singapore may be easier than Tokyo |
| **Switch script** | ⭐ | **Useless.** Every script calls the same `LaunchInstance` API. The bottleneck is server-side |

---

## Differences from upstream

```diff
+ src/OciApi.php
+   + updateInstanceShape()    # PUT /instances/{id} to change shapeConfig
+   + getInstanceVnics()       # GET /instances/{id}/vnics/
+   ~ assignPublicIp: false → true   # auto-assign public IP
+
+ index.php
+   + catch(TooManyRequestsWaiterException)  # 429 backoff no longer throws a fatal error
+   + Success branch: human-readable notification (public IP + SSH command)
+   + Auto-resize logic (configurable target, failure does not affect instance usability)
+
+ .github/workflows/ci.yml
+   + PHP syntax check on 8.1 / 8.2 / 8.3
+   + Smoke test: script runs without fatal error

```

---

## License

MIT (inherited from upstream)

## Credits

- [hitrov/oci-arm-host-capacity](https://github.com/hitrov/oci-arm-host-capacity) — the original project
- [ghkim887/oci-creator](https://github.com/ghkim887/oci-creator) — inspiration for the "grab small, then grow" strategy
