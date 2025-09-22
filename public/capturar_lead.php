<?php
// Define que a resposta será em formato JSON e permite requisições de qualquer origem (CORS)
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *"); // Em produção, restrinja para o seu domínio
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

// ===== CONFIGURAÇÕES DOS WEBHOOKS N8N =====
// Substitua estas URLs pelos webhooks reais do seu n8n
define('WEBHOOK_PALESTRA', 'https://seu-n8n.com/webhook/palestra-gratuita');
define('WEBHOOK_EBOOK', 'https://seu-n8n.com/webhook/ebook-pagamento');

// Define qual webhook usar (pode vir do formulário ou ser definido aqui)
// Opções: 'palestra' ou 'ebook'
$tipoWebhook = isset($data['tipo']) ? $data['tipo'] : 'palestra'; // padrão: palestra

// O PHP pode enviar uma requisição OPTIONS antes do POST. Isso a manipula corretamente.
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    exit(0);
}

// Verifica se o método da requisição é POST
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405); // Método não permitido
    echo json_encode(["status" => "error", "message" => "Método não permitido. Utilize POST."]);
    exit;
}

// Pega o corpo da requisição, que está em formato JSON
$json_data = file_get_contents("php://input");
$data = json_decode($json_data, true);

// 1. Validação dos Dados
$errors = [];
if (empty($data['nome'])) {
    $errors[] = "O campo 'nome' é obrigatório.";
}
if (empty($data['email'])) {
    $errors[] = "O campo 'e-mail' é obrigatório.";
} elseif (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
    $errors[] = "O formato do e-mail é inválido.";
}
if (empty($data['whatsapp'])) {
    $errors[] = "O campo 'WhatsApp' é obrigatório.";
}

if (!empty($errors)) {
    http_response_code(400); // Bad Request
    echo json_encode(["status" => "error", "message" => "Dados inválidos.", "errors" => $errors]);
    exit;
}

// 2. Sanitização dos Dados (Prevenção contra XSS)
$nome = htmlspecialchars(strip_tags(trim($data['nome'])));
$email = filter_var(trim($data['email']), FILTER_SANITIZE_EMAIL);
$whatsapp = htmlspecialchars(strip_tags(trim($data['whatsapp'])));
$data_captura = date("Y-m-d H:i:s"); // Adiciona data e hora da captura

// Determina o tipo de lead baseado em campo do formulário ou lógica customizada
$tipoWebhook = isset($data['tipo']) ? $data['tipo'] : 'palestra';

// 3. Salvando os Dados Localmente (backup)
$filePath = 'leads.csv';
$fileHeader = "Nome,Email,WhatsApp,DataCaptura,Tipo\n";

// Cria o arquivo e o cabeçalho se ele não existir
if (!file_exists($filePath)) {
    file_put_contents($filePath, $fileHeader);
}

// Formata a linha para o CSV (entre aspas para lidar com vírgulas nos dados)
$csvData = "\"{$nome}\",\"{$email}\",\"{$whatsapp}\",\"{$data_captura}\",\"{$tipoWebhook}\"\n";

// Adiciona a nova linha de dados ao final do arquivo
$savedLocally = file_put_contents($filePath, $csvData, FILE_APPEND | LOCK_EX);

// 4. Função para Enviar dados ao Webhook N8N
function enviarParaWebhook($url, $dados) {
    // Prepara os dados no formato esperado pelo webhook n8n
    $payload = json_encode([
        'name' => $dados['nome'],
        'email' => $dados['email'],
        'phone' => $dados['whatsapp']
    ]);
    
    // Configuração do contexto da requisição
    $options = [
        'http' => [
            'header'  => "Content-Type: application/json\r\n" .
                         "Accept: application/json\r\n",
            'method'  => 'POST',
            'content' => $payload,
            'timeout' => 30, // timeout de 30 segundos
            'ignore_errors' => true // captura resposta mesmo com erro HTTP
        ]
    ];
    
    $context = stream_context_create($options);
    
    // Envia a requisição
    $response = @file_get_contents($url, false, $context);
    
    // Verifica o código de resposta HTTP
    if ($response === false) {
        return [
            'success' => false,
            'error' => 'Falha na conexão com o webhook'
        ];
    }
    
    // Pega o código de status HTTP da resposta
    $http_response_header_str = implode("\n", $http_response_header);
    preg_match('/HTTP\/\d\.\d\s+(\d+)/', $http_response_header_str, $matches);
    $http_code = isset($matches[1]) ? intval($matches[1]) : 0;
    
    return [
        'success' => ($http_code >= 200 && $http_code < 300),
        'http_code' => $http_code,
        'response' => json_decode($response, true)
    ];
}

// 5. Escolhe qual webhook usar e envia os dados
$webhookUrl = ($tipoWebhook === 'ebook') ? WEBHOOK_EBOOK : WEBHOOK_PALESTRA;

// Array de dados para envio
$dadosWebhook = [
    'nome' => $nome,
    'email' => $email,
    'whatsapp' => $whatsapp
];

// Envia para o webhook n8n
$resultadoWebhook = enviarParaWebhook($webhookUrl, $dadosWebhook);

// 6. Resposta Final
if ($savedLocally && $resultadoWebhook['success']) {
    // Sucesso total
    http_response_code(200);
    echo json_encode([
        "status" => "success",
        "message" => "Dados recebidos e processados com sucesso!",
        "webhook_response" => $resultadoWebhook['response'],
        "tipo" => $tipoWebhook
    ]);
} elseif ($savedLocally && !$resultadoWebhook['success']) {
    // Salvou localmente mas falhou no webhook
    http_response_code(207); // Multi-Status
    echo json_encode([
        "status" => "partial_success",
        "message" => "Dados salvos localmente, mas houve falha no envio ao sistema de mensagens.",
        "webhook_error" => $resultadoWebhook['error'] ?? 'Erro desconhecido',
        "tipo" => $tipoWebhook
    ]);
} else {
    // Falha total
    http_response_code(500);
    echo json_encode([
        "status" => "error",
        "message" => "Falha ao processar os dados."
    ]);
}

// 7. Função Alternativa usando cURL (mais robusta - descomente se preferir)
/*
function enviarParaWebhookCurl($url, $dados) {
    if (!function_exists('curl_init')) {
        return [
            'success' => false,
            'error' => 'cURL não está disponível no servidor'
        ];
    }
    
    $payload = json_encode([
        'name' => $dados['nome'],
        'email' => $dados['email'],
        'phone' => $dados['whatsapp']
    ]);
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Accept: application/json'
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true); // Em produção, mantenha true
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);
    
    if ($response === false) {
        return [
            'success' => false,
            'error' => $curl_error ?: 'Erro desconhecido no cURL'
        ];
    }
    
    return [
        'success' => ($http_code >= 200 && $http_code < 300),
        'http_code' => $http_code,
        'response' => json_decode($response, true)
    ];
}

// Para usar cURL ao invés de file_get_contents, substitua a linha:
// $resultadoWebhook = enviarParaWebhook($webhookUrl, $dadosWebhook);
// Por:
// $resultadoWebhook = enviarParaWebhookCurl($webhookUrl, $dadosWebhook);
*/

?>
