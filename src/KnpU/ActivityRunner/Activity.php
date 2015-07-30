<?php

namespace KnpU\ActivityRunner;

use Doctrine\Common\Collections\Collection;
use KnpU\ActivityRunner\Activity\CodingChallengeInterface;

class Activity
{
    /**
     * @var Collection
     */
    private $inputFiles = array();

    private $challengeClassName;

    private $challengeClassContents;

    private $challengeObject;

    public function __construct($challengeClassName, $challengeClassContents)
    {
        $this->challengeClassName = $challengeClassName;
        $this->challengeClassContents = $challengeClassContents;
    }

    public function addInputFile($filename, $source)
    {
        $this->inputFiles[$filename] = $source;

        return $this;
    }

    public function getChallengeClassName()
    {
        return $this->challengeClassName;
    }

    public function getChallengeClassContents()
    {
        return $this->challengeClassContents;
    }

    public function getInputFiles()
    {
        return $this->inputFiles;
    }

    /**
     * @return CodingChallengeInterface
     */
    public function getChallengeObject()
    {
        if ($this->challengeObject === null) {
            $className = $this->challengeClassName;
            if (!class_exists($className)) {
                $classContents = trim($this->challengeClassContents);
                // look for <?php
                if (substr($classContents, 0, 5) == '<?php') {
                    $classContents = substr($classContents, 5);
                }

                // yep - we're doing this
                eval($classContents);
            }

            $this->challengeObject = new $className();
        }

        return $this->challengeObject;
    }
}
