<?php

namespace Oro\Bundle\NavigationBundle\Tests\Behat\Element;

use Behat\Mink\Element\NodeElement;
use Behat\Mink\Exception\ElementNotFoundException;
use Oro\Bundle\TestFrameworkBundle\Behat\Element\Element;

class MainMenu extends Element
{
    /**
     * @param string $path
     * @throws ElementNotFoundException
     * @return NodeElement|null
     */
    public function openAndClick($path)
    {
        $items = explode('/', $path);
        $linkLocator = trim(array_pop($items));
        $that = $this;

        while ($item = array_shift($items)) {
            /** @var NodeElement $link */
            $link = $that->findLink(trim($item));

            if (null === $link) {
                throw new ElementNotFoundException($this->getDriver(), 'link', 'id|title|alt|text', trim($item));
            }

            $link->mouseOver();
            $that = $link->getParent()->find('css', '.dropdown-menu');
        }

        $that->clickLink(trim($linkLocator));

        return $that->findLink($linkLocator);
    }
}
