<?php
	$outputs = $this->getVar("outputs");
	$commands = $this->getVar("commands");	
?>

<h1>Z3950</h1>

<p><a class="button btn btn-default" onclick="document.getElementById(&quot;caQuickSearchForm&quot;).submit();" href='#' onfocus="this.value='';">Dernière notice importée </a></p>

<script type="text/javascript"> 
  document.getElementById("caQuickSearchFormText").setAttribute('value','type_id:"49" AND modified:"<?php print $today;?>"');
</script>
