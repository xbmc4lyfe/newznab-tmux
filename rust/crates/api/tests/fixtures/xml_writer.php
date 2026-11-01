<?php

// Golden data for src/xml.rs: php xml_writer.php > xml_writer.json
$strings = ['plain', 'a&b<c>d"e\'f', "tab\tnl\ncr\r", 'ünï→𝄞', '', "\x01bad\x7f", ']]>'];
$out = [];
foreach ($strings as $string) {
    $clean = preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $string);
    $xml = new XMLWriter;
    $xml->openMemory();
    $xml->setIndent(false);
    $xml->startDocument('1.0', 'UTF-8');
    $xml->startElement('root');
    $xml->writeAttribute('a', $clean);
    $xml->writeElement('e', $clean);
    $xml->startElement('empty');
    $xml->endElement();
    $xml->startElement('t');
    $xml->writeAttribute('b', $clean);
    $xml->text($clean);
    $xml->endElement();
    $xml->startElement('c');
    $xml->writeCdata($clean);
    $xml->endElement();
    $xml->endElement();
    $xml->endDocument();
    $out[] = ['input' => $string, 'xml' => $xml->outputMemory()];
}
echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), "\n";
