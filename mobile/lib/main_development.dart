import 'core/config/env.dart';
import 'main.dart';

void main() async {
  Env.init(
    baseUrl: Env.defaultDevBaseUrl,
    environmentName: 'development',
  );
  await mainCommon();
}
