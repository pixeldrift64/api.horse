<?php
namespace App\Test\SyntaxHighlighter;

use App\SyntaxHighlighter\HtmlSyntaxHighlighter;
use DOMDocument;
use Gt\Dom\HTMLDocument;
use PHPUnit\Framework\TestCase;

class HtmlSyntaxHighlighterTest extends TestCase {
	public function testFormatIgnoresPreviousLibxmlErrors():void {
		$previousUseInternalErrors = libxml_use_internal_errors(true);
		(new DOMDocument())->loadHTML("<http-message></http-message>");

		try {
			$document = new HTMLDocument(
				"<http-message><div class='response-body syntax-highlight'></div></http-message>"
			);
			$element = $document->querySelector("http-message");

			(new HtmlSyntaxHighlighter())->format(
				$element,
				"<h1>Example Domain</h1>",
			);

			self::assertStringContainsString(
				"syntax-highlighter-html",
				$element->innerHTML,
			);
		}
		finally {
			libxml_clear_errors();
			libxml_use_internal_errors($previousUseInternalErrors);
		}
	}
}
