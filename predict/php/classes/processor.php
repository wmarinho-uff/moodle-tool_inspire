<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Php predictions processor
 *
 * @package   tool_inspire
 * @copyright 2016 David Monllao {@link http://www.davidmonllao.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace predict_php;

// TODO No support for 3rd party plugins psr4??
spl_autoload_register(function($class) {
    // Autoload Phpml classes.
    $path = __DIR__ . '/../phpml/src/' . str_replace('\\', '/', $class) . '.php';
    if (file_exists($path)) {
        require_once($path);
    }
});

use Phpml\NeuralNetwork\Network\MultilayerPerceptron;
use Phpml\NeuralNetwork\Training\Backpropagation;
use Phpml\CrossValidation\RandomSplit;
use Phpml\Dataset\ArrayDataset;
use Phpml\Metric\ConfusionMatrix;

use Phpml\Math\Statistic\Mean;
use Phpml\Math\Statistic\StandardDeviation;

defined('MOODLE_INTERNAL') || die();

/**
 * PHP predictions processor.
 *
 * @package   tool_inspire
 * @copyright 2016 David Monllao {@link http://www.davidmonllao.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class processor implements \tool_inspire\predictor {

    public function train($uniqueid, \stored_file $dataset, $outputdir) {
        throw new \Exception('Not implemented');
    }

    public function predict($uniqueid, \stored_file $dataset, $outputdir) {
        throw new \Exception('Not implemented');
    }

    public function evaluate($uniqueid, $minscore, $resultsdeviation, $niterations, \stored_file $dataset, $outputdir) {

        $fh = $dataset->get_content_file_handle();

        // The first lines are var names and the second one values.
        $metadata = fgetcsv($fh);
        $metadata = array_combine($metadata, fgetcsv($fh));

        // Skip headers.
        fgets($fh);

        // TODO This should be processed in chunks if we expect it to scale.
        $samples = array();
        $targets = array();
        while (($data = fgetcsv($fh)) !== false) {
            $sampledata = array_map('floatval', $data);
            $samples[] = array_slice($sampledata, 0, $metadata['nfeatures']);
            $targets[] = array(intval($data[$metadata['nfeatures']]));
        }
        fclose($fh);

        $phis = array();

        // Evaluate the model multiple times to confirm the results are not significantly random due to a short amount of data.
        for ($i = 0; $i < $niterations; $i++) {

            // Binary classification.
            $network = new MultilayerPerceptron([intval($metadata['nfeatures']), 2, 1]);
            $training = new Backpropagation($network);

            // Split up the dataset in training and testing.
            $data = new RandomSplit(new ArrayDataset($samples, $targets), 0.2);

            // For evaluation 0.1 error and 100 should be enough.
            $training->train($data->getTrainSamples(), $data->getTrainLabels(), 0.1, 100);

            $predictedlabels = array();
            $scores = array();
            foreach ($data->getTestSamples() as $input) {

                // This [0] must change if the output have more than 1 neuron.
                $probs = $network->setInput($input)->getOutput()[0];
                if ($probs >= 0.5) {
                    $predictedlabels[] = 1;
                    $scores[] = $probs;
                } else {
                    $predictedlabels[] = 0;
                    $scores[] = 1 - $probs;
                }
            }
            $testlabels = array_reduce($data->getTestLabels(), 'array_merge', array());

            $phis[] = $this->get_phi($testlabels, $predictedlabels);
        }

        // Let's fill the results changing the returned status code depending on the phi-related calculated metrics.
        return $this->get_result_object($phis, $resultsdeviation, $minscore);
    }

    protected function get_result_object($phis, $resultsdeviation, $minscore) {

        // We convert phi (from -1 to 1) to a value between 0 and 1.
        $avgphi = Mean::arithmetic($phis);

        // Standard deviation should ideally be calculated against the area under the curve.
        $stddev = StandardDeviation::population($phis);
        \tool_inspire\model::OK;

        // Let's fill the results object.
        $resultobj = new \stdClass();

        // Zero is ok, now we add other bits if something is not right.
        $resultobj->status = \tool_inspire\model::OK;
        $resultobj->errors = array();

        // Convert phi (from -1 to 1 to a value between 0 and 1) to a standard score.
        $resultobj->score = ($avgphi + 1) / 2;

        // If each iteration results varied too much we need more data to confirm that this is a valid model.
        if ($stddev > $resultsdeviation) {
            $resultobj->status = $resultobj->status + \tool_inspire\model::EVALUATE_NOT_ENOUGH_DATA;
            $resultobj->errors[] = 'The results obtained varied too much, we need more samples to check ' .
                'if this model is valid. Model deviation = ' . $stddev . ', accepted deviation = ' . $resultsdeviation;
        }

        if ($resultobj->score < $minscore) {
            $resultobj->status = $resultobj->status + \tool_inspire\model::EVALUATE_LOW_SCORE;
            $resultobj->errors[] = 'The model is not good enough. Model score = ' . $resultobj->score .
                ', minimum score = ' . $minscore;
        }


        return $resultobj;
    }

    protected function get_phi($testlabels, $predictedlabels) {
        // Binary here only as well.
        $matrix = ConfusionMatrix::compute($testlabels, $predictedlabels, array(0, 1));

        $tptn = $matrix[0][0] * $matrix[1][1];
        $fpfn = $matrix[1][0] * $matrix[0][1];
        $tpfp = $matrix[0][0] + $matrix[1][0];
        $tpfn = $matrix[0][0] + $matrix[0][1];
        $tnfp = $matrix[1][1] + $matrix[1][0];
        $tnfn = $matrix[1][1] + $matrix[0][1];
        if ($tpfp === 0 || $tpfn === 0 || $tnfp === 0 || $tnfn === 0) {
            $phi = 0;
        } else {
            $phi = ( $tptn - $fpfn ) / sqrt( $tpfp * $tpfn * $tnfp * $tnfn);
        }

        return $phi;
    }
}
