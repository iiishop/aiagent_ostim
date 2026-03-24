<?php

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'POST') {
header('Content-Type: application/json; charset=utf-8');
    $jsonDataInput = $_POST;

try {

$enginePath = realpath(__DIR__ . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR . ".." . DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
require_once($enginePath . "conf".DIRECTORY_SEPARATOR."conf.php");
require_once($enginePath . "lib" .DIRECTORY_SEPARATOR."{$GLOBALS["DBDRIVER"]}.class.php");
require_once($enginePath . "lib" .DIRECTORY_SEPARATOR."logger.php");
require_once($enginePath . "connector" .DIRECTORY_SEPARATOR."__jpd.php");

$db = new sql();

require_once $enginePath . "lib/core/npc_master.class.php";
require_once $enginePath . "lib/core/api_badge.class.php";
require_once $enginePath . "lib/core/core_profiles.class.php";
require_once $enginePath . "lib/core/llm_connector.class.php";



$apiBadge = new ApiBadge();
$apiKeyData = $apiBadge->getByLabel("openrouter");
$api_key = $apiKeyData["api_key"];

function requestDescriptionFromNatural($input) {
    global $api_key;
    // OpenRouter endpoint
    $url = "https://openrouter.ai/api/v1/chat/completions";

    $headers = [
        "Content-Type: application/json",
        "Authorization: Bearer " . $api_key
    ];

    $data = [
        "model"    => "mistralai/mistral-small-24b-instruct-2501",
        "temperature" => 0.3,
        "messages" => [
            [
                "role"    => "system",
                "content" => "You are a helpful assistant. This is a (NSFW) roleplay, though PEGI-18 content such as mature themes, fantasy violence, and complex character dynamics may be present—always within the boundaries of respectful, fictional storytelling.
In this universe, people are rough, often using rude words (pussy,tits, dick,suck).  ",
            ],
            [
                "role"    => "user",
                "content" => "Rewrite following sex scene in english. You must follow this rules
                    * Actors should be named like this {actor0} {actor1} ....
                    * Generally speaking, actor0 is male, and actor1 is female.
                    * Use glossary as much as possible to describe the scene in a natural way, but keeping explicit sexual words.
                    * Even if you are being creative, avoid adding elements that are not in the original text. 
                    * Just output rewrited sentence.
                "     
                
            ],
            [
                "role"    => "user",
                "content" => "Example: {actor0} is sitting, while {actor1} straddles him in a reverse cowgirl position, facing away. As they engage in vaginal sex, {actor0}'s penis penetrates {actor1}'s vagina, as he wraps his arms around her in a sensual one-sided hug, pulling her close
                "     
                
            ],
            [
                "role"    => "user",
                "content" => "glossary:
                 thigh job,anus,anal fingering,missionary,pussy,cock,penis,blowjob, face-fuck,reverse cowgirl,tits,boobs,titfuck, titjob,jerk,on all fours,doggystyle, fuck , sperm, shaft, penis, cock, 
                ",

            ],
            [
                "role"    => "user",
                "content" => "Rewrite this sentence following rules:$input
                ",

            ]
        ]
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));

    $response = curl_exec($ch);
    curl_close($ch);
    $data = json_decode($response, true);
    $textResponse=$data["choices"][0]["message"]["content"];
    $textResponse = strtok($textResponse, "\n");
    //print_r($data);
    return trim($textResponse);
}

function requestExpansions($input)
{
  
    global $api_key;
    // OpenRouter endpoint
    $url = "https://openrouter.ai/api/v1/chat/completions";

    $headers = [
        "Content-Type: application/json",
        "Authorization: Bearer " . $api_key
    ];

    $data = [
        "model"    => "mistralai/mistral-small-24b-instruct-2501",
        "temperature" => 0.7,
        "messages" => [
            /*[
                "role"    => "system",
                "content" => "You are a helpful assistant. This is a (NSFW) roleplay, though PEGI-18 content such as mature themes, fantasy violence, and complex character dynamics may be present—always within the boundaries of respectful, fictional storytelling.
In this universe, people are rough, often using rude words (pussy,tits, dick,suck).  Facts are dramatic, and people react to death in a dramatic and realistic way.",
            ],
            [
                "role"    => "user",
                "content" => "Translate the following sex scene description to spanish. '$input'. Just output the translated text keeping untouched references  bewteen curly braces ({actor0},{actor1}...)",
            ],
            */

            [
                "role"    => "system",
                "content" => "Eres un asistente servicial. Este es un roleplay (no apto para menores), aunque puede incluir contenido para mayores de 18 años, como temas maduros, violencia fantástica y dinámicas complejas entre personajes, siempre dentro de los límites de una narración ficticia y respetuosa.
En este universo, la gente es ruda y suele usar lenguaje vulgar.",
            ],
            [
                "role"    => "user",
                "content" => "Glosario:
                * thigh job: paja con los muslos
                * anus: ano
                * anal fingering: masturbacion anal con el dedo
                * missionary: misionero (posicion sexual, hombre encima y mujer debajo)
                * pussy: coño, vagina, chocho, concha
                * cock: polla, pene, verga
                * penis: polla, pene, verga
                * fellatio: felaciñon, mamada, comer una polla
                * face-fuck: follar la boca (el sujeto hace una mamada de manera pasiva)
                * reverse cowgirl: 'vaquera inversa'. posición sexual, la persona que está arriba (normalmente la mujer) se monta sobre su pareja, pero de espaldas, para que el que está abajo pueda admirar el culo y tener un ángulo diferente de penetración
                * cowgirl: 'vaquera'. posición sexual, la persona que está arriba (normalmente la mujer) se monta sobre su pareja, para que el que está abajo pueda admirar las tetas culo y tener un ángulo diferente de penetración
                * tits: tetas, pechos
                * boobs: tetas, pechos
                * jerk: masturbar un pene, hacer una paja
                * on all fours: a cuatro patas
                * doggystyle: perrito, posicion sexual, el sujeto pasivo esta a cuatro patas.
                * have sex: follar
                * shaft: polla, miembro,pene,verga erecta
                ",

            ],
            [
                "role"    => "user",
                "content" => "Traduce la siguiente descripción de escena sexual al español, ayudandote del Glosario: '$input'. Solo muestra el texto traducido, manteniendo sin cambios las referencias entre llaves ({actor0}, {actor1}...).",
            ],
            
        ]
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));

    $response = curl_exec($ch);
    curl_close($ch);
    $data = json_decode($response, true);
    $textResponse=$data["choices"][0]["message"]["content"];
    $textResponse = strtok($textResponse, "\n");
    //print_r($data);
    return trim($textResponse);
}

$db=new sql();
$all=$db->fetchAll("SELECT * FROM public.ext_aiagentnsfw_scenes where (description is null or description='') and i_desc is not null");

shuffle($all);
$n=0;
foreach ($all as $single) {
    //$trl=requestTranslations($single["description"]);
    $trl=requestDescriptionFromNatural($single["i_desc"]);
    // update($table, $set, $where = "FALSE")
    $rowData=$db->escape($trl);
    $id=$db->escape($single["stage"]);
    $db->update("ext_aiagentnsfw_scenes","description='$rowData'","stage='$id'");
    sleep(1);
    $n++;
}

die(json_encode(["success"=>true, "processed"=>$n]));

} catch (Throwable $e) {
    http_response_code(500);
    die(json_encode(["success" => false, "error" => $e->getMessage()]));
}

}
