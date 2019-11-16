<?php
namespace ttempleton\nocache\twig;

use Craft;
use craft\helpers\StringHelper;

use Twig\Compiler as TwigCompiler;
use Twig\Node\Node as TwigNode;

use ttempleton\nocache\Plugin as NoCache;

/**
 * Class Node
 *
 * @package ttempleton\nocache\twig
 * @author Benjamin Fleming
 * @author Thomas Templeton
 * @since 2.0.0
 */
class Node extends TwigNode
{
	/**
	 * @var string
	 */
	private $id;

	public function __construct(TwigNode $body, TwigNode $context, int $line, string $tag = null, int $counter = null)
	{
		parent::__construct([
			'body' => $body,
			'context' => $context,
		], [], $line, $tag);

		$this->setSourceContext($body->getSourceContext());

		if ($counter !== null)
		{
			$this->id = (string)$counter;
		}
		else
		{
			$this->id = StringHelper::randomString(24);
		}
	}

	public function compile(TwigCompiler $compiler)
	{
		$compiler->addDebugInfo($this);

		// Generate an ID for the `nocache` block
		$templateClassName = $compiler->getEnvironment()->getTemplateClass($this->getSourceContext()->getName());
		$id = hash('sha256', $templateClassName . $this->id);

		// Create a wrapper node for the internals of the `nocache` block
		// This will serve as the node that'll actually render the contents of that block, whereas this node's purpose
		// is to render the placeholder tags
		$bodyNode = $this->getNode('body');
		$bodyNode->setSourceContext($this->getSourceContext());

		$body = new Node_Body($bodyNode, $id, $this->lineno, $this->tag);

		// Compile the internals to a separate compiled template file for later use
		NoCache::$plugin->methods->compile($id, $body);

		// Capture the passed context
		$contextNode = $this->getNode('context');

		if (!empty($contextNode->nodes))
		{
			$compiler->raw('$subContext = ')->subcompile($contextNode)->raw(";\n");
		}
		else
		{
			$compiler->write('$subContext = [];');
		}

		$compiler
			// Only bother tagging the output for post-processing if caching is enabled
			->write('if (' . NoCache::class . '::$plugin->methods->isCacheEnabled())')
			->write('{')

				// 1. Saves the captured context at that point in rendering to the cache. This is so when rendering the
				//    internals of the `nocache` block later on, the context can be revived and will work as per usual.
				// 2. Renders the placeholder tag which will later be replaced by the rendered body of the `nocache` tag.
				->write('$contextId = ' . StringHelper::class . '::randomString(8);')
				->write(Craft::class . "::\$app->getCache()->set('nocache_{$id}_' . \$contextId, \$subContext, 0);")
				->write("echo '<no-cache>{$id}-' . \$contextId . '</no-cache>';")

			->write('}')
			->write('else')
			->write('{')

				// Otherwise render the template with the context directly
				->write("echo " . NoCache::class . "::\$plugin->methods->render('{$id}', \$subContext);")

			->write('}');
	}
}
