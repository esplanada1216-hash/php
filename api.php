<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);


// Configura tus credenciales de Spotify
define('SPOTIFY_CLIENT_ID', '363efb40421f40c1af9f4ef11c697168'); // Reemplaza con tu client_id
define('SPOTIFY_CLIENT_SECRET', 'c4ff252eba7744e3822893133f272ccb'); // Reemplaza con tu client_secret

function getSpotifyToken() {
    $url = 'https://accounts.spotify.com/api/token';
    $headers = [
        'Authorization: Basic ' . base64_encode(SPOTIFY_CLIENT_ID . ':' . SPOTIFY_CLIENT_SECRET)
    ];
    $data = [
        'grant_type' => 'client_credentials'
    ];

    $options = [
        'http' => [
            'header'  => implode("\r\n", $headers),
            'method'  => 'POST',
            'content' => http_build_query($data)
        ]
    ];

    $context  = stream_context_create($options);
    $response = @file_get_contents($url, false, $context);

    if ($response === false) {
        return null;
    }

    $tokenData = json_decode($response, true);
    return $tokenData['access_token'] ?? null;
}

function getMp3StreamTitle($streamingUrl, $interval) {
    $needle = 'StreamTitle=';
    $headers = [
        'Icy-MetaData: 1',
        'User-Agent: Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/27.0.1453.110 Safari/537.36'
    ];

    $context = stream_context_create([
    'http' => [
        'header' => implode("\r\n", $headers),
        'timeout' => 60 // Incrementar timeout a 60 segundos
    ]
]);

    $stream = @fopen($streamingUrl, 'r', false, $context);
    if ($stream === false) {
        return null;
    }

    $metaDataInterval = null;
    foreach ($http_response_header as $header) {
        if (stripos($header, 'icy-metaint') !== false) {
            $metaDataInterval = (int)trim(explode(':', $header)[1]);
            break;
        }
    }

    if ($metaDataInterval === null) {
        fclose($stream);
        return null;
    }

    while (!feof($stream)) {
        fread($stream, $metaDataInterval);
        $buffer = fread($stream, $interval);
        $titleIndex = strpos($buffer, $needle);
        if ($titleIndex !== false) {
            $title = substr($buffer, $titleIndex + strlen($needle));
            $title = substr($title, 0, strpos($title, ';'));
            fclose($stream);
            return trim($title, "' ");
        }
    }
    fclose($stream);
    return null;
}

function extractArtistAndSong($title) {
    $title = trim($title, "'");
    if (strpos($title, '-') !== false) {
        [$artist, $song] = explode('-', $title, 2);
        return [trim($artist), trim($song)];
    }
    return ['', trim($title)];
}
function getAlbumInfo($artist, $song) {
    // 1. Limpa termos extras comuns em rádios (parênteses, colchetes, "tema de")
    $clean_artist = trim(preg_replace('/[\(\[][^\)\]]*[\)\]]/', '', $artist));
    $clean_song = trim(preg_replace('/[\(\[][^\)\]]*[\)\]]/', '', $song));
    
    // 2. Monta a busca otimizada para o iTunes
    $termo_busca = urlencode($clean_artist . ' ' . $clean_song);
    $url = "https://apple.com{$termo_busca}&limit=1&media=music";
    
    $headers = [
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
    ];
    
    $options = [
        'http' => [
            'header' => implode("\r\n", $headers),
            'method' => 'GET',
            'timeout' => 5
        ]
    ];
    
    $context = stream_context_create($options);
    $response = @file_get_contents($url, false, $context);
    
    if ($response !== false) {
        $data = json_decode($response, true);
        
        // CORREÇÃO: Verifica se a lista existe e se possui pelo menos 1 resultado [0]
        if (isset($data['results'][0])) {
            $track = $data['results'][0];
            $album = $track['collectionName'] ?? 'No disponible';
            $year = isset($track['releaseDate']) ? substr($track['releaseDate'], 0, 4) : 'No disponible';
            $genre = $track['primaryGenreName'] ?? 'No disponible';
            $durationMs = $track['trackTimeMillis'] ?? 0;
            
            // Pega a imagem padrão do iTunes e altera para o tamanho máximo em alta resolução
            $artworkUrl = $track['artworkUrl100'] ?? null;
            if ($artworkUrl) {
                $artworkUrl = str_replace('100x100bb', '600x600bb', $artworkUrl);
            }
            
            return [$artworkUrl, $album, $year, $genre, $durationMs];
        }
    }
    
    // Imagem padrão caso o iTunes não encontre o cantor
    $imagem_padrao = "https://radiotemasdenovelas.com";
    return [$imagem_padrao, 'No disponible', 'No disponible', 'No disponible', 0];
}

function updateHistory($url, $artist, $song) {
    $historyFile = 'history_' . md5($url) . '.json';
    $historyLimit = 10;

    if (!file_exists($historyFile)) {
        $history = [];
    } else {
        $history = json_decode(file_get_contents($historyFile), true);
        if ($history === null) {
            $history = [];
        }
    }

    $currentSong = ["title" => $song, "artist" => $artist];
    $existingIndex = array_search($currentSong, array_column($history, 'song'));
    if ($existingIndex !== false) {
        array_splice($history, $existingIndex, 1);
    }

    array_unshift($history, ["song" => $currentSong]);
    $history = array_slice($history, 0, $historyLimit);
    file_put_contents($historyFile, json_encode($history));

    return $history;
}

// Funcion Para Leer Las Canciones
header('Content-Type: application/json');

// URL de streaming
$url = isset($_GET['url']) ? $_GET['url'] : null; 
$interval = isset($_GET['interval']) ? (int)$_GET['interval'] : 19200;

if ($url === null) {
    echo json_encode(["error" => "URL parameter is missing"]); // User-friendly error message
    exit;
}

// Intentar obtener el start_time desde el archivo
$start_time_file = 'start_time_' . md5($url) . '.txt';
$previous_song_file = 'previous_song_' . md5($url) . '.txt';

if (file_exists($previous_song_file)) {
    // Leer la canción anterior desde el archivo
    $previous_song = file_get_contents($previous_song_file);
} else {
    $previous_song = null;
}

if (file_exists($start_time_file)) {
    // Si el archivo existe, leer el start_time desde él
    $start_time = (int)file_get_contents($start_time_file);
} else {
    // Si no existe, asignar un start_time basado en la hora actual
    $start_time = time();
    // Guardar el start_time en el archivo
    file_put_contents($start_time_file, $start_time);
}

if (!filter_var($url, FILTER_VALIDATE_URL)) {
    echo json_encode(["error" => "Invalid URL format"]); // More specific error message
    exit;
}


$title = getMp3StreamTitle($url, $interval);
if ($title) {
    [$artist, $song] = extractArtistAndSong($title);

    // Si la canción ha cambiado, reiniciar el start_time
    if ($song !== $previous_song) {
        // Reiniciar el start_time
        $start_time = time();
        file_put_contents($start_time_file, $start_time);
        file_put_contents($previous_song_file, $song); // Guardar la canción actual
    }

    [$artUrl, $album, $year, $genre, $durationMs] = getAlbumInfo($artist, $song);

    // Convertimos la duración de la canción de milisegundos a segundos
    $duration = $durationMs / 1000;  // Duración de la canción en segundos

    // Calcular el tiempo transcurrido desde que se inició la canción
    $elapsed = time() - $start_time; // Tiempo transcurrido en segundos
    $elapsed = min($elapsed, $duration); // Limitar el tiempo transcurrido al tiempo total de la canción

    // Calcular el tiempo restante
    $remaining = max(0, $duration - $elapsed); // Tiempo restante, no puede ser negativo

    // Convertir todo a enteros antes de enviar la respuesta
    $elapsed = (int) $elapsed;   // Elapsed como entero
    $remaining = (int) $remaining; // Remaining como entero
    $duration = (int) $duration;   // Duration como entero

    // Actualizar historial de canciones
    $history = updateHistory($url, $artist, $song);
    $filteredHistory = array_slice($history, 1);

    $response = [
        "songtitle" => "$artist - $song",
        "artist" => $artist,
        "song" => $song,
        "source" => $url,
        "artwork" => $artUrl,
        "album" => $album,
        "year" => $year,
        "genre" => $genre,
        "song_history" => $filteredHistory,
        "now_playing" => [
            "elapsed" => $elapsed,   // Elapsed como entero
            "remaining" => $remaining, // Remaining como entero
            "duration" => $duration   // Duration como entero
        ]
    ];

    // Responder con la información en formato JSON
    echo json_encode($response);
} else {
    echo json_encode(["error" => "The stream title could not be retrieved."]);
}
