<?php

use Behat\Behat\Context\ClosuredContextInterface,
	Behat\Behat\Context\TranslatedContextInterface,
	Behat\Behat\Context\BehatContext,
	Behat\Behat\Exception\PendingException;
use Behat\Gherkin\Node\PyStringNode,
	Behat\Gherkin\Node\TableNode;

use Behat\MinkExtension\Context\MinkContext;
use WebDriver\Exception\NoAlertOpenError;

/**
 * Features context.
 */
class FeatureContext extends MinkContext {

	/**
	 * @Then /echo the content/
	 */
	public function echoHTML() {
		echo $this->getSession()->getDriver()->getCurrentUrl();
		echo $this->getSession()->getDriver()->getContent();
	}


	/**
	 * @Then /^I wait for ([\d]*) seconds$/
	 */
	public function iWaitForSeconds( $arg1 ) {
		sleep( intval( $arg1 ) );
	}

}