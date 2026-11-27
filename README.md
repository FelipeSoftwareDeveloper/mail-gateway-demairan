# Demairan Mail Gateway

Gateway de e-mails transacionais via SMTP do **Titan Email**, feito para hospedagem compartilhada (HostGator / cPanel).
PHP 8.1+, MySQL/MariaDB com PDO, PHPMailer. Painel com projetos, contas SMTP, tokens de API restritos e logs de auditoria.

```
mail-gateway/
├── api/send.php          ← endpoint que suas aplicações chamam
├── app/                  ← núcleo (bloqueado ao navegador via .htaccess)
│   ├── bootstrap.php  db.php  crypto.php  security.php
│   ├── logger.php  mailer.php  layout.php  helpers.php
├── assets/               ← CSS/JS do painel (design system Demairan)
├── cron/cleanup.php      ← limpeza de logs antigos (somente CLI)
├── lib/PHPMailer/src/    ← você coloca o PHPMailer aqui
├── index.php             ← visão geral
├── projects.php  smtp.php  tokens.php  logs.php  log.php  admins.php
├── login.php  logout.php  install.php (apague depois de instalar)
├── config.sample.php     ← copie para config.php
├── database.sql
└── .htaccess
```

---

## 1. Instalação na HostGator (sem SSH)

### 1.1 Versão do PHP
cPanel → **MultiPHP Manager** → selecione o domínio → **PHP 8.1** ou superior.
Em **Select PHP Version / Extensions**, confirme `pdo_mysql`, `openssl` e `mbstring` marcados (já vêm por padrão).

### 1.2 Banco de dados
1. cPanel → **Bancos de dados MySQL**.
2. Crie o banco (ex.: `cpuser_mailgw`) e um usuário com senha forte.
3. Em "Adicionar usuário ao banco", vincule os dois com **TODOS OS PRIVILÉGIOS**.
4. cPanel → **phpMyAdmin** → selecione o banco → aba **Importar** → envie `database.sql`.

### 1.3 Arquivos
1. Compacte esta pasta em `.zip` no seu computador.
2. cPanel → **Gerenciador de Arquivos** → `public_html` (ou um subdomínio, ex.: `mail.seudominio.com.br`) → **Carregar** o zip → clique com o botão direito → **Extract**.
3. Recomendado: use um subdomínio próprio (`mail.` ou `gateway.`) só para o gateway.

### 1.4 PHPMailer (upload manual)
1. Acesse <https://github.com/PHPMailer/PHPMailer/releases> e baixe **Source code (zip)** da versão mais recente (6.9+).
2. Extraia no seu computador. Dentro dele há uma pasta `src/`.
3. No Gerenciador de Arquivos, entre em `lib/PHPMailer/` e envie a pasta **`src` inteira** (ou zipe só a `src`, envie e extraia ali).
4. O resultado precisa ser:
   ```
   lib/PHPMailer/src/Exception.php
   lib/PHPMailer/src/PHPMailer.php
   lib/PHPMailer/src/SMTP.php
   ```
> Com Composer disponível, `composer require phpmailer/phpmailer` na raiz também funciona: o sistema usa `vendor/autoload.php` automaticamente.

### 1.5 Configuração
1. Duplique `config.sample.php` como **`config.php`** e edite: dados do banco e `app_key`.
2. Para gerar a `app_key` sem SSH, abra `https://seu-dominio/install.php`: se a chave estiver vazia, ele mostra uma nova, gerada na hora. Cole no `config.php`.
3. **Guarde a `app_key` em local seguro** (ex.: gerenciador de senhas). Sem ela, as senhas SMTP salvas não podem mais ser lidas.
4. (Recomendado) Mova o conteúdo real para fora do `public_html`, por exemplo `/home/SEUUSUARIO/mail-gateway-config.php`, e deixe o `config.php` do projeto apenas com:
   ```php
   <?php return require '/home/SEUUSUARIO/mail-gateway-config.php';
   ```

### 1.6 HTTPS
cPanel → **SSL/TLS Status** → rode o **AutoSSL** para o domínio/subdomínio. Com `force_https => true` (padrão), o painel redireciona para HTTPS e a API recusa chamadas em HTTP.

### 1.7 Primeiro acesso
1. Abra `https://seu-dominio/install.php`, confira a lista de verificação e crie o administrador (senha com 12+ caracteres).
2. **Apague o `install.php`** pelo Gerenciador de Arquivos (o painel avisa enquanto ele existir).
3. Entre em `/login.php`.

### 1.8 Cron de limpeza (opcional, recomendado)
cPanel → **Cron Jobs** → uma vez por dia:
```
/usr/local/bin/php /home/SEUUSUARIO/public_html/cron/cleanup.php >/dev/null 2>&1
```
Apaga logs mais antigos que `logs.retention_days` (padrão 90) e tentativas de login com mais de 7 dias.

---

## 1.9 Atualizar uma instalação existente

1. **Banco primeiro:** no phpMyAdmin, rode as migrações em `migrations/` que ainda não foram aplicadas, em ordem (`002_mcp.sql`, `003_resend.sql`…). Elas só acrescentam tabelas e colunas, então funcionam também com o código antigo.
2. **Depois o código:** cPanel → **Git™ Version Control** → **Gerenciar** → **Pull or Deploy** → **Update from Remote** → **Deploy HEAD Commit**. O `.cpanel.yml` copia os arquivos para a pasta do site sem tocar no `config.php` nem no PHPMailer.

A ordem importa: o código novo usa as colunas criadas pelas migrações.

---

## 2. Uso do painel

1. **Contas de envio** → cadastre o remetente e escolha o provedor:
   - **Titan (SMTP):** a caixa do Titan (ex.: `avisos@demaria.com.br`). Padrões: `smtp.titan.email`, porta **465 / SSL** (ou **587 / STARTTLS**).
   - **Resend (API):** o e-mail do remetente e a API key do Resend (`re_…`). Veja a seção 2.1.

   Clique em **Testar** para validar sem enviar e-mail. O provedor é escolhido no cadastro e não muda depois.
2. **Projetos** → crie "Sistema ERP", "Site Institucional" etc.
3. **Tokens de API** → escolha nome, projeto e conta de envio → **Gerar token**. O token completo (`tk_live_…`) aparece **uma única vez**, com botão de copiar e exemplo de `curl`. No banco fica só o hash SHA-256.
4. **Revogar** bloqueia o token na hora (próxima requisição recebe 401). Tokens revogados podem ser excluídos; os logs ficam.
5. **Logs** → filtros por status, provedor, projeto, token, período e busca por e-mail/assunto/IP. O corpo registrado aparece como texto puro, nunca renderizado.

### 2.1 Usar o Resend em vez do Titan

Cada conta de envio tem um provedor. Para um sistema enviar pelo Resend, cadastre uma conta com provedor **Resend** e gere o token dela. As aplicações não mudam nada: mesmo endpoint, mesmo JSON, mesma regra do `from`.

1. **No Resend:** crie a conta e adicione o domínio (ex.: `demairan.com.br`).
2. **Na Cloudflare:** crie os registros DNS que o Resend mostrar:
   - TXT `resend._domainkey` (DKIM). Convive com o `titan1._domainkey`.
   - MX e TXT no subdomínio `send`. O SPF do domínio raiz não muda.
   - Recomendado: TXT `_dmarc` com `v=DMARC1; p=none;` para monitorar.
3. Espere o domínio ficar **Verified** no Resend.
4. Gere uma **API key** com "Sending access" restrita ao domínio.
5. **No painel do gateway:** Contas de envio → provedor **Resend (API)** → e-mail, nome de exibição e API key → **Cadastrar** → **Testar**.
   - O teste confirma que a chave funciona e que o domínio está verificado.
   - Com uma chave restrita a envio, o Resend não deixa listar domínios: o teste aceita a chave e avisa para conferir o domínio no painel do Resend.
6. Gere um token para essa conta em **Tokens de API**.

Observações:
- Se a HostGator bloquear conexões de saída para `api.resend.com`, o **Testar** mostra "Não foi possível conectar ao Resend". Nesse caso, abra um chamado pedindo a liberação.
- A API key fica criptografada no banco (AES-256-GCM, como a senha do Titan) e nunca aparece no painel nem no MCP.
- Os limites (envios por segundo e cota mensal) dependem do seu plano no Resend. O erro de limite aparece no log com a mensagem do Resend.

---

## 3. API

### `POST /api/send.php`

| Header | Valor |
| --- | --- |
| `Authorization` | `Bearer tk_live_...` |
| `Content-Type` | `application/json` |

```json
{
  "from": "avisos@demaria.com.br",
  "to": "cliente@exemplo.com",
  "subject": "Seu código de verificação",
  "html": "<h1>Olá!</h1><p>Seu código é 1234.</p>",
  "text": "Olá! Seu código é 1234."
}
```

| Campo | Obrigatório | Observação |
| --- | --- | --- |
| `from` | não | Se enviado, **precisa** ser a conta vinculada ao token. Diferente → **403**. Se omitido, usa a conta do token. Aceita `"Nome <email>"`: o nome (até 100 caracteres) vira o nome de exibição deste envio. |
| `to` | sim | String ou lista. |
| `cc`, `bcc` | não | String ou lista. Total de destinatários ≤ `max_recipients` (50). |
| `reply_to` | não | Um e-mail. |
| `subject` | sim | Até 255 caracteres. |
| `html` / `text` | ao menos um | Titan com só `html`: a versão texto é gerada automaticamente. |
| `headers` | não | Objeto `{"Nome": "valor"}`, até 10. Só `List-Unsubscribe`, `List-Unsubscribe-Post`, `In-Reply-To`, `References` e headers `X-` (menos `X-Mailer`, `X-Originating-IP` e `X-Priority`). Outro nome ou quebra de linha → **422**. Vale para Titan e Resend. |
| `idempotency_key` | não | Até 256 caracteres (`A-Z a-z 0-9 _ - : .`). Com o Resend, repetir a requisição com a mesma chave não envia o e-mail de novo. No Titan não tem efeito. |

O endereço do remetente vem sempre da conta de envio do token. O nome de exibição também, a não ser que o `from` traga um nome (ex.: `"Loja da Ana <avisos@demairan.com.br>"`), para uma plataforma enviar em nome de clientes dela pela mesma conta. Funciona igual no Titan e no Resend.

**Sucesso (200)**
```json
{ "success": true, "log_id": 812, "provider": "titan", "message_id": "<abc@demaria.com.br>", "from": "avisos@demaria.com.br", "to": ["cliente@exemplo.com"] }
```
Com o Resend, `provider` é `"resend"` e `message_id` é o ID do e-mail no Resend.

**Erro**
```json
{ "success": false, "error": { "code": "sender_not_allowed", "message": "Este token só pode enviar como avisos@demaria.com.br." }, "log_id": 813 }
```

| HTTP | `code` | Quando |
| --- | --- | --- |
| 400 | `invalid_json` | Corpo não é JSON |
| 401 | `invalid_token` | Token ausente, malformado ou inexistente |
| 401 | `token_revoked` | Token revogado |
| 403 | `sender_not_allowed` | `from` diferente da conta do token |
| 403 | `smtp_account_disabled` | Conta de envio desativada no painel |
| 403 | `https_required` | Chamada em HTTP com `force_https` ligado |
| 405 | `method_not_allowed` | Método diferente de POST |
| 413 | `payload_too_large` | Corpo acima de `max_body_bytes` |
| 422 | `validation_error` | Campo obrigatório faltando ou e-mail inválido |
| 429 | `rate_limited` | Mais de `rate_limit_per_minute` envios do token por minuto |
| 429 | `too_many_failed_attempts` | IP com muitas tentativas inválidas (bloqueio temporário) |
| 502 | `smtp_error` | O provedor (Titan ou Resend) recusou ou não respondeu. O detalhe vem em `message` e no log; o código é o mesmo nos dois provedores, para não quebrar integrações. **Titan:** quando o servidor respondeu, `smtp_code` (ex.: `550`, `450`) e `smtp_code_ex` (ex.: `5.1.1`) vêm junto em `error`; 5xx é recusa definitiva e 4xx é temporária. **Resend:** `resend_status` traz o status HTTP da API (ex.: `403`, `429`). |
| 503 | `service_unavailable` | Banco indisponível |

### ⚠️ Proteção anti-robô da HostGator

Em alguns servidores da HostGator há uma proteção na frente do site. Uma requisição sem o cookie dela recebe **HTTP 409 Conflict** com este corpo, e não o JSON da API:

```html
<script>document.cookie = "humans_21909=1"; document.location.reload(true)</script>
```

Navegadores passam sozinhos, mas chamadas de servidor (PHP, Node, cURL) param aí. Há dois caminhos:

1. **Definitivo:** abra um chamado na HostGator pedindo que desativem a "proteção contra robôs" (cookie `humans_`) no subdomínio do gateway, porque ele recebe chamadas de API de servidor para servidor.
2. **Contorno imediato:** mande o cookie em toda chamada, com o header `Cookie: humans_21909=1`. O número (`21909`) muda de servidor para servidor: use o que aparece no script acima. Se um dia ele mudar, a API volta a responder 409 com o novo número.

Para o exemplo de `curl` da tela de tokens já sair com o cookie, preencha `'hosting_cookie' => 'humans_21909=1'` dentro de `'api'` no `config.php`.

Os exemplos abaixo já incluem o cookie. Quando a HostGator desativar a proteção, ele pode ser removido.

### Exemplos

**cURL**
```bash
curl -X POST https://mail.seudominio.com.br/api/send.php \
  -H "Authorization: Bearer tk_live_SEU_TOKEN" \
  -H "Content-Type: application/json" \
  -H "Cookie: humans_21909=1" \
  -d '{"from":"avisos@demaria.com.br","to":"cliente@exemplo.com","subject":"Teste","html":"<p>Olá!</p>"}'
```

**PHP**
```php
$ch = curl_init('https://mail.seudominio.com.br/api/send.php');
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_HTTPHEADER => [
        'Authorization: Bearer ' . getenv('MAIL_GATEWAY_TOKEN'),
        'Content-Type: application/json',
        'Cookie: humans_21909=1', // proteção anti-robô da HostGator
    ],
    CURLOPT_POSTFIELDS => json_encode([
        'from' => 'avisos@demaria.com.br',
        'to' => 'cliente@exemplo.com',
        'subject' => 'Seu código de verificação',
        'html' => '<h1>Olá!</h1><p>Seu código é 1234.</p>',
    ]),
]);
$response = json_decode(curl_exec($ch), true);
$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
```

**Node.js (18+)**
```js
const res = await fetch('https://mail.seudominio.com.br/api/send.php', {
  method: 'POST',
  headers: {
    Authorization: `Bearer ${process.env.MAIL_GATEWAY_TOKEN}`,
    'Content-Type': 'application/json',
    Cookie: 'humans_21909=1', // proteção anti-robô da HostGator
  },
  body: JSON.stringify({ to: 'cliente@exemplo.com', subject: 'Teste', text: 'Olá!' }),
});
const data = await res.json();
```

> A API não envia cabeçalhos CORS de propósito: chame sempre do **servidor** da sua aplicação. Um token em JavaScript de navegador fica visível para qualquer visitante.

---

## 4. Conectar o Claude (MCP)

O endpoint `POST /mcp.php` é um servidor MCP (Model Context Protocol, transporte Streamable HTTP). Com ele, o Claude consulta e administra o gateway.

### 4.1 Ativar
1. **Instalação feita antes desta versão:** no phpMyAdmin, aba **SQL**, rode o conteúdo de `migrations/002_mcp.sql` (e depois o `003_resend.sql`, se ainda não rodou). Instalações novas já recebem as tabelas pelo `database.sql`.
2. No painel, abra **Acesso MCP** e gere uma chave (`mcp_…`):
   - **Somente leitura:** status, listas de projetos, contas, tokens e logs, e teste de conta SMTP.
   - **Leitura e escrita:** também cadastra projetos, contas SMTP e tokens, e revoga tokens.
3. A chave aparece uma única vez. A tela já mostra os comandos de conexão prontos.

### 4.2 Claude Code
```bash
claude mcp add --transport http demairan-mail https://mail-gateway.demairan.com.br/mcp.php \
  --header "Authorization: Bearer mcp_SUA_CHAVE" \
  --header "Cookie: humans_21909=1"
```
O header `Cookie` só é necessário enquanto a proteção anti-robô da HostGator estiver ativa (veja a seção 3).

### 4.3 Claude Desktop
O Desktop conecta servidores remotos pelo `mcp-remote` (precisa de Node.js). Em `claude_desktop_config.json`:
```json
{
  "mcpServers": {
    "demairan-mail": {
      "command": "npx",
      "args": ["-y", "mcp-remote", "https://mail-gateway.demairan.com.br/mcp.php",
               "--header", "Authorization:${MCP_AUTH}",
               "--header", "Cookie:${MCP_COOKIE}"],
      "env": { "MCP_AUTH": "Bearer mcp_SUA_CHAVE", "MCP_COOKIE": "humans_21909=1" }
    }
  }
}
```

> **claude.ai (web):** conectores personalizados do claude.ai usam OAuth e não deixam enviar headers como o cookie da HostGator. Por isso esse cliente não é suportado nesta versão.

### 4.4 Ferramentas

| Ferramenta | Permissão | O que faz |
| --- | --- | --- |
| `get_status` | leitura | Envios e falhas de hoje, bloqueios em 24h, totais e série de 7 dias |
| `list_projects` | leitura | Projetos com tokens ativos e total enviado |
| `list_smtp_accounts` | leitura | Contas de envio e o provedor de cada uma (senhas e API keys nunca são retornadas) |
| `test_smtp_account` | leitura | Testa a conta sem enviar e-mail (login SMTP no Titan; chave e domínio no Resend) |
| `list_tokens` | leitura | Tokens (só o prefixo), com filtro por status e projeto |
| `search_logs` | leitura | Busca por status, provedor, projeto, token, texto e período |
| `get_log` | leitura | Detalhe completo de um registro |
| `create_project` | escrita | Cadastra projeto |
| `create_smtp_account` | escrita | Cadastra remetente Titan (`password`) ou Resend (`provider: "resend"` + `api_key`), com o segredo criptografado |
| `create_token` | escrita | Gera token de envio; o valor completo aparece só nessa resposta |
| `revoke_token` | escrita | Revoga um token na hora |

Toda ação de escrita fica registrada em **Acesso MCP → Ações feitas pelo Claude**, com argumentos (sem senha), resultado e IP.

**Atenção:** ao cadastrar uma conta SMTP ou gerar um token pelo Claude, a senha ou o token passa pela conversa com o Claude. Se preferir que isso não aconteça, faça esses cadastros pelo painel.

---

## 5. Segurança implementada

| Ponto | Como |
| --- | --- |
| SQL Injection | PDO com prepared statements nativos (`EMULATE_PREPARES=false`) em todas as consultas |
| Senhas SMTP | AES-256-GCM (`openssl_encrypt`) com `app_key` de 32 bytes; IV aleatório por senha; adulteração no banco é detectada |
| Tokens de API | 160 bits aleatórios (`random_bytes`), salvos como SHA-256; exibidos uma vez; prefixo `tk_live_xxxxxxxx` para identificação |
| Vínculo token → remetente | O token só envia como a conta SMTP dele; tentativa diferente → 403 e log `unauthorized` |
| Força bruta na API | IP com `auth_fail_limit` tentativas inválidas em `auth_fail_window_minutes` recebe 429 |
| Abuso de token válido | Limite de envios por minuto por token |
| Senhas de admin | `password_hash` (bcrypt) com rehash automático; verificação com tempo uniforme |
| Login do painel | Limite de tentativas por IP, `session_regenerate_id`, cookie `HttpOnly` + `Secure` + `SameSite=Strict`, expiração por inatividade |
| CSRF | Token por sessão em todo formulário, comparado com `hash_equals`; logout só por POST |
| XSS | Toda saída passa por `htmlspecialchars`; CSP sem `unsafe-inline`; corpo dos e-mails no log exibido como texto |
| Header injection | Quebras de linha rejeitadas em e-mails, assunto e nome de exibição |
| Arquivos internos | `.htaccess` bloqueia `app/`, `lib/`, `cron/`, `migrations/`, `config.php` e arquivos `.sql` |
| Cabeçalhos | `X-Frame-Options`, `nosniff`, `Referrer-Policy`, HSTS sob HTTPS, `Cache-Control: no-store` |
| Acesso MCP | Chaves separadas dos tokens de envio, salvas como SHA-256; escopo leitura ou escrita; revogação imediata; auditoria das escritas; `Origin` validado contra DNS rebinding; força bruta limitada por IP |

---

## 6. Problemas comuns

- **A API responde 409 com `<script>document.cookie = "humans_...` em vez de JSON** — é a proteção anti-robô da HostGator. Veja a seção "Proteção anti-robô da HostGator" acima.
- **O subdomínio não abre ("não foi possível encontrar o servidor")** — se o DNS do domínio estiver na Cloudflare, criar o subdomínio no cPanel não basta: crie também um registro A na Cloudflare apontando para o IP do servidor da HostGator (o mesmo dos outros subdomínios que já funcionam).
- **Toda chamada retorna 401 com token correto** — o Apache está removendo o `Authorization`. Confirme que o `.htaccess` da raiz foi enviado (arquivos que começam com ponto ficam ocultos: no Gerenciador de Arquivos, **Configurações → Mostrar arquivos ocultos**). Como alternativa, envie o token no header `X-Api-Key: tk_live_...`.
- **"SMTP Error: Could not authenticate"** — senha errada, ou a caixa no Titan está sem acesso a apps de terceiros. Teste com o botão **Testar** em Contas SMTP.
- **Timeout na porta 465** — tente 587 com STARTTLS. Algumas hospedagens bloqueiam SMTP externo; nesse caso, abra um chamado na HostGator pedindo liberação de saída para `smtp.titan.email` nas portas 465/587.
- **"Não foi possível descriptografar a senha SMTP"** — a `app_key` mudou. Restaure a chave antiga ou cadastre as senhas novamente.
- **Erro 500 em branco** — veja o `error_log` na pasta do site (Gerenciador de Arquivos) ou em cPanel → **Erros**.
- **Limites do Titan** — o Titan limita envios por caixa e por dia conforme o plano. Para volumes altos, distribua entre várias contas (um token por conta) ou use um provedor dedicado a e-mail transacional.
